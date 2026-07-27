<?php

namespace App\Gateways\PayPal;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPal Expanded Checkout (Card Fields) — Orders API v2.
 *
 * @see https://developer.paypal.com/docs/checkout/advanced/integrate/
 */
class PayPalDriver implements GatewayDriver
{
    private const BASE_URL_LIVE = 'https://api-m.paypal.com';

    private const BASE_URL_SANDBOX = 'https://api-m.sandbox.paypal.com';

    /** Currencies with zero decimal places in PayPal amount.value. */
    private const ZERO_DECIMAL_CURRENCIES = [
        'HUF', 'JPY', 'TWD',
    ];

    public function testConnection(array $credentials): bool
    {
        try {
            $token = $this->getAccessToken($credentials);
            return $token !== '';
        } catch (\Throwable $e) {
            Log::debug('PayPalDriver testConnection failed', ['message' => $e->getMessage()]);

            return false;
        }
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl
    ): array {
        throw new \RuntimeException('PayPal não suporta PIX neste gateway. Use cartão de crédito.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('PayPal não suporta boleto neste gateway. Use cartão de crédito.');
    }

    /**
     * Cria Order PayPal (INTENT=CAPTURE).
     *
     * @param  array<string, mixed>  $credentials
     * @param  array{name?: string, email?: string, phone?: string, document?: string, country?: string, locale?: string}  $consumer
     * @param  string  $mode  'card_fields' (ACDC/3DS) ou 'buttons' (Checkout padrão / wallet)
     * @return array{transaction_id: string, status: string, raw?: array}
     */
    public function createPayPalOrder(
        array $credentials,
        float $amount,
        string $currency,
        string $externalId,
        array $consumer = [],
        string $description = '',
        string $mode = 'buttons'
    ): array {
        $currency = strtoupper(trim($currency) ?: 'USD');
        if (strlen($currency) !== 3) {
            $currency = 'USD';
        }

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => substr('order_'.$externalId, 0, 256),
                    'custom_id' => substr((string) $externalId, 0, 127),
                    'description' => substr($description !== '' ? $description : ('Order '.$externalId), 0, 127),
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => $this->formatAmount($amount, $currency),
                    ],
                ],
            ],
            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'locale' => $this->normalizePaypalLocale((string) ($consumer['locale'] ?? 'pt_BR')),
            ],
        ];

        // Só Card Fields (ACDC) usa payment_source.card no create; Buttons/wallet quebra com isso.
        if ($mode === 'card_fields') {
            $body['payment_source'] = [
                'card' => [
                    'attributes' => [
                        'verification' => [
                            'method' => 'SCA_WHEN_REQUIRED',
                        ],
                    ],
                ],
            ];
        }

        $email = trim((string) ($consumer['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $payer = ['email_address' => $email];
            $name = trim((string) ($consumer['name'] ?? ''));
            if ($name !== '') {
                $parts = preg_split('/\s+/', $name, 2) ?: [];
                $given = trim((string) ($parts[0] ?? ''));
                $surname = trim((string) ($parts[1] ?? ''));
                if ($given === '') {
                    $given = 'Cliente';
                }
                if ($surname === '') {
                    $surname = $given;
                }
                $payer['name'] = [
                    'given_name' => substr($given, 0, 140),
                    'surname' => substr($surname, 0, 140),
                ];
            }

            // PayPal aceita só national_number (dígitos, máx. 14) — sem "+"
            $phoneDigits = preg_replace('/\D+/', '', (string) ($consumer['phone'] ?? '')) ?? '';
            if (strlen($phoneDigits) > 14) {
                $phoneDigits = substr($phoneDigits, -14);
            }
            if (strlen($phoneDigits) >= 8) {
                $payer['phone'] = [
                    'phone_type' => 'MOBILE',
                    'phone_number' => [
                        'national_number' => $phoneDigits,
                    ],
                ];
            }

            $country = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($consumer['country'] ?? '')) ?? '');
            if (strlen($country) === 2) {
                $payer['address'] = [
                    'country_code' => $country,
                ];
            }

            $body['payer'] = $payer;
        }

        $response = $this->http($credentials)->post($this->baseUrl($credentials).'/v2/checkout/orders', $body);

        if (! $response->successful()) {
            Log::warning('PayPalDriver createPayPalOrder failed', [
                'order_id' => $externalId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException($this->friendlyErrorMessage($response->json(), 'Não foi possível iniciar o pagamento PayPal.'));
        }

        $data = $response->json();
        $paypalOrderId = (string) ($data['id'] ?? '');
        if ($paypalOrderId === '') {
            throw new \RuntimeException('PayPal: resposta sem ID do pedido.');
        }

        return [
            'transaction_id' => $paypalOrderId,
            'status' => strtolower((string) ($data['status'] ?? 'created')),
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * Captura Order PayPal já aprovada pelo Card Fields (payment_token = PayPal Order ID).
     *
     * @param  array<string, mixed>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array{payment_token: string, card_mask?: string, currency?: string}  $card
     * @return array{transaction_id: string, status?: string, capture_id?: string}
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $paypalOrderId = trim((string) ($card['payment_token'] ?? ''));
        if ($paypalOrderId === '') {
            throw new \RuntimeException('PayPal: pedido inválido. Tente novamente.');
        }

        $response = $this->http($credentials)->withHeaders([
            'PayPal-Request-Id' => 'capture-'.$externalId.'-'.substr($paypalOrderId, -12),
        ])->post($this->baseUrl($credentials).'/v2/checkout/orders/'.$paypalOrderId.'/capture', (object) []);

        if (! $response->successful()) {
            $json = $response->json();
            Log::warning('PayPalDriver capture failed', [
                'order_id' => $externalId,
                'paypal_order_id' => $paypalOrderId,
                'status' => $response->status(),
                'body' => $json,
            ]);
            $name = is_array($json) ? (string) ($json['name'] ?? '') : '';
            $issue = '';
            if (is_array($json) && isset($json['details'][0]['issue'])) {
                $issue = (string) $json['details'][0]['issue'];
            }
            if ($name === 'INSTRUMENT_DECLINED' || $issue === 'INSTRUMENT_DECLINED') {
                throw new \RuntimeException('Cartão recusado. Verifique os dados ou tente outro cartão.');
            }
            throw new \RuntimeException($this->friendlyErrorMessage($json, 'Não foi possível processar o pagamento. Tente novamente.'));
        }

        $data = $response->json();
        $status = strtoupper((string) ($data['status'] ?? ''));
        $captureId = $this->extractCaptureId($data);
        $captureStatus = $this->extractCaptureStatus($data);

        $mapped = $this->mapCaptureStatus($status, $captureStatus);

        return [
            'transaction_id' => $paypalOrderId,
            'status' => $mapped,
            'capture_id' => $captureId,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return null;
        }

        try {
            // Prefer Orders API (gateway_id is PayPal order id)
            $response = $this->http($credentials)->get(
                $this->baseUrl($credentials).'/v2/checkout/orders/'.$transactionId
            );
            if ($response->successful()) {
                $data = $response->json();
                $status = strtoupper((string) ($data['status'] ?? ''));
                $captureStatus = $this->extractCaptureStatus($data);
                if ($status === 'COMPLETED' || $captureStatus === 'COMPLETED') {
                    return 'paid';
                }
                if (in_array($status, ['VOIDED', 'CANCELLED'], true) || in_array($captureStatus, ['DECLINED', 'FAILED'], true)) {
                    return 'cancelled';
                }

                return 'pending';
            }

            // Fallback: capture id
            $cap = $this->http($credentials)->get(
                $this->baseUrl($credentials).'/v2/payments/captures/'.$transactionId
            );
            if ($cap->successful()) {
                $st = strtoupper((string) ($cap->json('status') ?? ''));
                if ($st === 'COMPLETED') {
                    return 'paid';
                }
                if (in_array($st, ['DECLINED', 'FAILED', 'REFUNDED'], true)) {
                    return 'cancelled';
                }

                return 'pending';
            }
        } catch (\Throwable $e) {
            Log::debug('PayPalDriver getTransactionStatus failed', [
                'tx' => $transactionId,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Verifica assinatura do webhook via API PayPal.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, string>  $headers  Normalized lowercase header map
     * @param  array<string, mixed>  $webhookEvent  Decoded JSON body
     */
    public function verifyWebhookSignature(array $credentials, array $headers, array $webhookEvent): bool
    {
        $webhookId = trim((string) ($credentials['webhook_id'] ?? ''));
        if ($webhookId === '') {
            return false;
        }

        $payload = [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $webhookEvent,
        ];

        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $required) {
            if ($payload[$required] === '') {
                return false;
            }
        }

        try {
            $response = $this->http($credentials)->post(
                $this->baseUrl($credentials).'/v1/notifications/verify-webhook-signature',
                $payload
            );
            if (! $response->successful()) {
                Log::warning('PayPalDriver verifyWebhookSignature HTTP failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return false;
            }

            return strtoupper((string) ($response->json('verification_status') ?? '')) === 'SUCCESS';
        } catch (\Throwable $e) {
            Log::warning('PayPalDriver verifyWebhookSignature error', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function baseUrl(array $credentials): string
    {
        $sandbox = isset($credentials['sandbox']) && filter_var($credentials['sandbox'], FILTER_VALIDATE_BOOLEAN);

        return $sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_LIVE;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function getAccessToken(array $credentials): string
    {
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $clientSecret = trim((string) ($credentials['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('PayPal: Client ID e Client Secret são obrigatórios.');
        }

        $sandbox = isset($credentials['sandbox']) && filter_var($credentials['sandbox'], FILTER_VALIDATE_BOOLEAN);
        $cacheKey = 'paypal_access_token.'.md5($clientId.'|'.($sandbox ? '1' : '0'));

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl($credentials).'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            Log::warning('PayPalDriver OAuth failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            throw new \RuntimeException('PayPal: falha na autenticação. Verifique Client ID/Secret e o ambiente (sandbox/produção).');
        }

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new \RuntimeException('PayPal: token de acesso vazio.');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 300);
        $ttl = max(60, min(3000, $expiresIn - 60));
        Cache::put($cacheKey, $token, now()->addSeconds($ttl));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function http(array $credentials, int $timeout = 25): \Illuminate\Http\Client\PendingRequest
    {
        $token = $this->getAccessToken($credentials);

        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withOptions(['connect_timeout' => min(60, max(2, (int) ceil($timeout / 4)))]);
    }

    /**
     * Locale do checkout (pt_BR, en, es) → formato da Orders API (pt-BR, en-US, es-ES).
     */
    private function normalizePaypalLocale(string $locale): string
    {
        $raw = str_replace('-', '_', trim($locale));
        $lower = strtolower($raw);
        if ($lower === 'en' || str_starts_with($lower, 'en_')) {
            return 'en-US';
        }
        if ($lower === 'es' || str_starts_with($lower, 'es_')) {
            return 'es-ES';
        }
        if ($lower === 'pt' || str_starts_with($lower, 'pt_')) {
            return 'pt-BR';
        }
        if (preg_match('/^([a-z]{2})_([a-z]{2})$/i', $raw, $m)) {
            return strtolower($m[1]).'-'.strtoupper($m[2]);
        }

        return 'en-US';
    }

    private function formatAmount(float $amount, string $currency): string
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (string) (int) round($amount);
        }

        return number_format($amount, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>|mixed  $data
     */
    private function extractCaptureId(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }
        $captures = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (is_array($captures) && ! empty($captures['id'])) {
            return (string) $captures['id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|mixed  $data
     */
    private function extractCaptureStatus(mixed $data): string
    {
        if (! is_array($data)) {
            return '';
        }
        $captures = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (is_array($captures)) {
            return strtoupper((string) ($captures['status'] ?? ''));
        }

        return '';
    }

    private function mapCaptureStatus(string $orderStatus, string $captureStatus): string
    {
        if ($orderStatus === 'COMPLETED' || $captureStatus === 'COMPLETED') {
            return 'paid';
        }
        if (in_array($captureStatus, ['PENDING', 'DECLINED', 'FAILED'], true)) {
            return $captureStatus === 'PENDING' ? 'pending' : 'cancelled';
        }
        if (in_array($orderStatus, ['APPROVED', 'CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED'], true)) {
            return 'pending';
        }

        return 'pending';
    }

    /**
     * @param  array<string, mixed>|mixed  $json
     */
    private function friendlyErrorMessage(mixed $json, string $fallback): string
    {
        if (! is_array($json)) {
            return $fallback;
        }
        $message = (string) ($json['message'] ?? '');
        $detail = '';
        if (isset($json['details'][0]['description']) && is_string($json['details'][0]['description'])) {
            $detail = $json['details'][0]['description'];
        }
        if ($detail !== '') {
            return $detail;
        }
        if ($message !== '') {
            return $message;
        }

        return $fallback;
    }
}
