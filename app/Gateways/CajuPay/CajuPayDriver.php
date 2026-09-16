<?php

namespace App\Gateways\CajuPay;

use App\Gateways\Contracts\GatewayDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\MoneyMinorUnits;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CajuPayDriver implements GatewayDriver
{
    private function baseUrl(array $credentials): string
    {
        $override = isset($credentials['base_url']) ? trim((string) $credentials['base_url']) : '';
        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function httpForCredentials(array $credentials): \Illuminate\Http\Client\PendingRequest
    {
        $public = trim((string) ($credentials['public_key'] ?? ''));
        $secret = trim((string) ($credentials['secret_key'] ?? ''));
        if ($public === '' || $secret === '') {
            throw new \RuntimeException('CajuPay: informe a chave pública (X-API-Key) e a chave secreta (X-API-Secret) em Integrações > Gateways.');
        }

        $base = $this->baseUrl($credentials);

        return Http::acceptJson()
            ->asJson()
            ->timeout(25)
            ->withOptions(['connect_timeout' => 10])
            ->baseUrl($base)
            ->withHeaders([
                'X-API-Key' => $public,
                'X-API-Secret' => $secret,
            ]);
    }

    public function testConnection(array $credentials): bool
    {
        if (! $this->hasApiKeys($credentials)) {
            return false;
        }

        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/wallet/balance', ['kind' => 'main']);

            if ($response->successful()) {
                return true;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                return false;
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver testConnection', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function hasApiKeys(array $credentials): bool
    {
        return trim((string) ($credentials['public_key'] ?? '')) !== ''
            && trim((string) ($credentials['secret_key'] ?? '')) !== '';
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        ?string $splitId = null,
        ?string $partnerCheckoutUrl = null,
    ): array {
        unset($postbackUrl);
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure a chave pública e a chave secreta da API (painel CajuPay → API / Chaves).');
        }

        $amountCents = MoneyMinorUnits::toMinorUnits($amount, 'BRL');
        if ($amountCents < 1) {
            throw new \RuntimeException('CajuPay: valor inválido.');
        }

        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));
        $name = $this->sanitizeName((string) ($consumer['name'] ?? ''));
        $email = $this->sanitizeEmail((string) ($consumer['email'] ?? ''));
        $phone = $this->normalizePhoneForCajuPay((string) ($consumer['phone'] ?? ''));

        $idempotencyKey = 'getfy-' . $externalId . '-' . Str::lower(Str::random(8));

        $consumerPayload = [
            'name' => $name,
            'email' => $email !== '' ? $email : 'cliente@checkout.local',
            'document' => $document,
        ];
        if ($phone !== null) {
            $consumerPayload['phone'] = $phone;
        }

        $body = [
            'amount_cents' => $amountCents,
            'currency' => 'BRL',
            'description' => 'Pedido #'.$externalId,
            'product_ref' => 'order-'.$externalId,
            'customer_ref' => 'getfy-order-'.$externalId,
            'consumer' => $consumerPayload,
        ];
        if ($splitId) {
            $body['split_id'] = $splitId;
        }
        $this->applyPartnerCheckoutUrl($body, $partnerCheckoutUrl);

        $response = $this->httpForCredentials($credentials)
            ->withHeaders(['Idempotency-Key' => Str::limit($idempotencyKey, 200, '')])
            ->post('/api/payments/pix', $body);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao criar cobrança PIX.'
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay: resposta inválida.');
        }

        $paymentId = $data['payment_id'] ?? '';
        if (! is_string($paymentId) || $paymentId === '') {
            throw new \RuntimeException('CajuPay: payment_id ausente na resposta.');
        }

        $qr = $data['pix_qr_code'] ?? null;
        $copy = $data['pix_copy_paste'] ?? null;

        return [
            'transaction_id' => $paymentId,
            'qrcode' => is_string($qr) ? $qr : null,
            'copy_paste' => is_string($copy) ? $copy : null,
            'raw' => $data,
        ];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        if ($transactionId === '') {
            return null;
        }

        // SDK session tokens are public and don't need API keys; try them first
        // when the format suggests a session token (no underscore prefix typical of
        // payment_id UUIDs and length > 20 chars).
        if ($this->looksLikeSdkSessionToken($transactionId)) {
            $sdkStatus = $this->getSdkSessionStatus($transactionId, $credentials);
            if ($sdkStatus !== null) {
                return $sdkStatus;
            }
        }

        // Pedidos CajuPay guardam checkout_session_id (UUID) em gateway_id. O endpoint
        // público GET /sdk/public/checkout/sessions/{id} responde por esse id; a heurística
        // acima evita UUID para não confundir com payment_id — aqui tentamos a sessão
        // primeiro e, se não for sessão (404 / vazio), caímos em /api/payments.
        if ($this->looksLikeUuid($transactionId)) {
            $sdkStatus = $this->getSdkSessionStatus($transactionId, $credentials);
            if ($sdkStatus !== null) {
                return $sdkStatus;
            }
        }

        if (! $this->hasApiKeys($credentials)) {
            return null;
        }

        if ($this->looksLikeUuid($transactionId)) {
            $directStatus = $this->getPaymentStatusById($transactionId, $credentials);
            if ($directStatus !== null) {
                return $directStatus;
            }
        }

        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/payments', ['limit' => 100]);

            if (! $response->successful()) {
                return null;
            }

            $list = $response->json();
            if (! is_array($list)) {
                return null;
            }

            foreach ($list as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $pid = $item['payment_id'] ?? null;
                if (! is_string($pid) || $pid !== $transactionId) {
                    continue;
                }

                return $this->normalizePaymentStatus($item['status'] ?? null);
            }
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver getTransactionStatus', ['message' => $e->getMessage()]);

            return null;
        }

        return null;
    }

    /**
     * Heuristic: SDK session tokens are long opaque strings (>20 chars) that
     * we typically pass through. Payment IDs are UUIDs (36 chars with dashes).
     * To avoid mis-routing valid UUID payment_ids, only treat as SDK token
     * when the string contains characters outside UUID format OR when length
     * differs from 36.
     */
    private function looksLikeSdkSessionToken(string $value): bool
    {
        if (strlen($value) < 20) {
            return false;
        }
        // UUID v4: 8-4-4-4-12 hex with dashes
        if ($this->looksLikeUuid($value)) {
            return false;
        }

        return true;
    }

    private function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function getPaymentStatusById(string $paymentId, array $credentials): ?string
    {
        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/payments/'.urlencode($paymentId));

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            return $this->normalizePaymentStatus($data['status'] ?? null);
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver getPaymentStatusById', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Public endpoint — no auth needed, uses the session token directly.
     *
     * @param  array<string, mixed>  $credentials  Used only to resolve baseUrl override.
     */
    public function getSdkSessionStatus(string $token, array $credentials = []): ?string
    {
        if ($token === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withOptions(['connect_timeout' => 10])
                ->baseUrl($this->baseUrl($credentials))
                ->get('/api/sdk/public/checkout/sessions/'.urlencode($token));

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            $raw = $this->extractPublicSessionStatus($data);
            $normalized = $this->normalizePaymentStatus($raw);
            if ($normalized !== null && $normalized !== 'paid' && $normalized !== 'pending' && $normalized !== 'cancelled') {
                Log::debug('CajuPayDriver getSdkSessionStatus: status não mapeado para paid/pending/cancelled', [
                    'raw' => $raw,
                    'normalized' => $normalized,
                ]);
            }

            return $normalized;
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver getSdkSessionStatus', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Extrai o estado de pagamento do JSON de GET /api/sdk/public/checkout/sessions/{token}.
     * O contrato CajuPay separa ciclo de vida da sessão (`status`: active/…) de
     * liquidação (`payment_status`: pending|paid|…). Preferir payment_status — se
     * lermos `status` primeiro, sessões pagas ficam eternamente como "active".
     *
     * @param  array<string, mixed>  $data
     */
    private function extractPublicSessionStatus(array $data): mixed
    {
        foreach (['payment_status', 'checkout_status', 'session_status'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $v = $data[$key];
            if (is_string($v) && trim($v) !== '') {
                return $v;
            }
        }

        foreach (['payment', 'latest_payment', 'charge', 'latest_charge'] as $nest) {
            $obj = $data[$nest] ?? null;
            if (! is_array($obj)) {
                continue;
            }
            foreach (['payment_status', 'status', 'state'] as $key) {
                if (! array_key_exists($key, $obj)) {
                    continue;
                }
                $v = $obj[$key];
                if (is_string($v) && trim($v) !== '' && ! $this->isSessionLifecycleOnlyStatus($v)) {
                    return $v;
                }
            }
        }

        // Último recurso: status/state da sessão (ignorar "active" = sessão aberta).
        foreach (['status', 'state'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $v = $data[$key];
            if (is_string($v) && trim($v) !== '' && ! $this->isSessionLifecycleOnlyStatus($v)) {
                return $v;
            }
        }

        return null;
    }

    private function isSessionLifecycleOnlyStatus(string $status): bool
    {
        // "active"/"created" = sessão aberta na CajuPay, não resultado do pagamento.
        return in_array(strtolower(trim($status)), ['active', 'created'], true);
    }

    /**
     * Lê o array `methods_available` (rota pública, sem auth) da sessão. Esse array
     * é a interseção entre as flags da sessão (allow_card/allow_boleto/allow_*_pay) e
     * o que a conta da CajuPay realmente liberou no PSP/admin. Métodos fora dessa lista
     * causam `method_not_available` no confirm — então a gente filtra do lado de cá.
     *
     * @param  array<string, mixed>  $credentials  Usado apenas pra resolver baseUrl override.
     * @return array<int, string>  Slugs CajuPay (ex.: ['card', 'apple_pay', 'pix']) — pode
     *                             estar vazio se a sessão não trouxer methods_available.
     */
    public function getSessionAvailableMethods(string $token, array $credentials = []): array
    {
        if ($token === '') {
            return [];
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withOptions(['connect_timeout' => 10])
                ->baseUrl($this->baseUrl($credentials))
                ->get('/api/sdk/public/checkout/sessions/'.urlencode($token));

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            if (! is_array($data)) {
                return [];
            }

            $methods = $data['methods_available'] ?? ($data['available_methods'] ?? []);
            if (! is_array($methods)) {
                return [];
            }

            // Normaliza pra os slugs internos do Getfy. A CajuPay usa 'applepay'/'googlepay'
            // (sem underscore) no SDK e na API; nosso checkout usa 'apple_pay'/'google_pay'.
            $normalized = [];
            foreach ($methods as $m) {
                $slug = strtolower(trim((string) $m));
                if ($slug === 'applepay') $slug = 'apple_pay';
                if ($slug === 'googlepay') $slug = 'google_pay';
                if (in_array($slug, ['card', 'boleto', 'pix', 'apple_pay', 'google_pay'], true)) {
                    $normalized[] = $slug;
                }
            }

            return array_values(array_unique($normalized));
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver getSessionAvailableMethods', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Create an SDK checkout session on CajuPay (server-side, with API keys).
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $consumer  Optional initial payer info.
     * @param  array<int, string>  $allowedMethods  Subset of ['card','apple_pay','google_pay','pix'].
     * @param  array{
     *     allow_card_installments?: bool,
     *     card_max_installments?: int,
     *     require_card_threeds?: bool,
     *     save_card?: bool
     * }  $cardOptions  Cartão Brasil: parcelamento / 3DS / save_card (só aplicados com allow_card).
     * @return array{token: string, checkout_session_id: string, raw: array<string, mixed>}
     */
    public function createSdkCheckoutSession(
        array $credentials,
        int $amountCents,
        string $currency,
        string $description,
        string $externalId,
        array $consumer,
        array $allowedMethods,
        string $defaultMethod,
        ?string $locale = null,
        ?string $partnerCheckoutUrl = null,
        array $cardOptions = [],
    ): array {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure a chave pública e a chave secreta da API (painel CajuPay → API / Chaves).');
        }

        if ($amountCents < 1) {
            throw new \RuntimeException('CajuPay: valor inválido.');
        }

        $currencyCode = MoneyMinorUnits::normalizeCurrencyCode($currency);
        if (in_array('pix', $allowedMethods, true) && $currencyCode !== 'BRL') {
            throw new \RuntimeException('CajuPay: PIX só pode ser cobrado em BRL.');
        }

        $allowCard = in_array('card', $allowedMethods, true);

        $body = [
            'amount_cents' => $amountCents,
            'currency' => $currencyCode,
            'description' => $description !== '' ? $description : ('Pedido #'.$externalId),
            'allow_card' => $allowCard,
            'allow_boleto' => in_array('boleto', $allowedMethods, true),
            'allow_pix' => in_array('pix', $allowedMethods, true),
            'allow_apple_pay' => in_array('apple_pay', $allowedMethods, true),
            'allow_google_pay' => in_array('google_pay', $allowedMethods, true),
            'metadata' => [
                'external_id' => $externalId,
                'source' => 'getfy',
            ],
        ];

        // Cartão Brasil (módulo 06): parcelamento sem juros + require_card_threeds.
        // Só quando o caller passou flags (fluxo payment_method=card). Wallets omitem.
        if ($allowCard && array_key_exists('allow_card_installments', $cardOptions)) {
            $allowInstallments = ! empty($cardOptions['allow_card_installments']);
            $maxInstallments = min(12, max(1, (int) ($cardOptions['card_max_installments'] ?? 1)));
            if ($allowInstallments && $maxInstallments >= 2) {
                $body['allow_card_installments'] = true;
                $body['card_max_installments'] = $maxInstallments;
            } else {
                $body['allow_card_installments'] = false;
            }
        }
        if ($allowCard && ! empty($cardOptions['require_card_threeds'])) {
            $body['require_card_threeds'] = true;
        }
        if ($allowCard && ! empty($cardOptions['save_card'])) {
            $body['save_card'] = true;
        }

        // initial_payer só é enviado quando temos dados REAIS do cliente. A CajuPay
        // não casa esses dados com o que vai no confirm — o /confirm lê payer_name /
        // payer_email / payer_document do payload do POST público (controller.confirm
        // do SDK), e o initial_payer da sessão é apenas um pré-preenchimento opcional.
        // Mandar placeholder ("Cliente") só polui o pré-preenchimento e nem entra em
        // produção. Confirmado pelo time CajuPay (docs-cajupay.md, Q&A).
        $rawName = trim((string) ($consumer['name'] ?? ''));
        $email = $this->sanitizeEmail((string) ($consumer['email'] ?? ''));
        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));
        $phone = $this->normalizePhoneForCajuPay((string) ($consumer['phone'] ?? ''));

        $payer = array_filter([
            'name' => $rawName !== '' ? $this->sanitizeName($rawName) : null,
            'email' => $email !== '' ? $email : null,
            'document' => $document !== '' && $document !== '00000000000' ? $document : null,
            'phone' => $phone,
        ], static fn ($v) => $v !== null && $v !== '');

        if (! empty($payer)) {
            $body['initial_payer'] = $payer;
        }

        if ($defaultMethod !== '') {
            $body['default_method'] = $defaultMethod;
        }

        $localeTag = trim((string) $locale);
        if ($localeTag !== '') {
            $body['locale'] = mb_substr($localeTag, 0, 16);
        }
        $this->applyPartnerCheckoutUrl($body, $partnerCheckoutUrl);

        $idempotencyKey = 'getfy-sdk-'.$externalId.'-'.Str::lower(Str::random(8));

        $response = $this->httpForCredentials($credentials)
            ->withHeaders(['Idempotency-Key' => Str::limit($idempotencyKey, 200, '')])
            ->post('/api/sdk/v1/checkout/sessions', $body);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao criar sessão de checkout.'
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay: resposta inválida ao criar sessão.');
        }

        $token = $data['token'] ?? null;
        $sessionId = $data['checkout_session_id'] ?? ($data['id'] ?? null);

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('CajuPay: token ausente na resposta da sessão.');
        }
        if (! is_string($sessionId) || $sessionId === '') {
            throw new \RuntimeException('CajuPay: checkout_session_id ausente na resposta da sessão.');
        }

        return [
            'token' => $token,
            'checkout_session_id' => $sessionId,
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<int, array<string, mixed>>
     */
    public function listWebhookEndpoints(array $credentials): array
    {
        if (! $this->hasApiKeys($credentials)) {
            return [];
        }

        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/webhooks/endpoints');

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            if (! is_array($data)) {
                return [];
            }

            // Backend may return { items: [...] } or array directly.
            if (isset($data['items']) && is_array($data['items'])) {
                $data = $data['items'];
            }

            return array_values(array_filter($data, static fn ($it) => is_array($it)));
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver listWebhookEndpoints', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Idempotent webhook registration (platform bootstrap — CajuPay module 22).
     *
     * @param  array<string, mixed>  $credentials
     * @return array{endpoint_id: string, signing_secret: string|null, created: bool, already_exists: bool, raw: array<string, mixed>}
     */
    public function registerWebhookEndpoint(
        array $credentials,
        string $url,
        bool $rotateIfExists = false,
        ?string $description = null
    ): array {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API antes de registrar o webhook.');
        }
        if ($url === '') {
            throw new \RuntimeException('CajuPay: URL do webhook vazia.');
        }

        $host = parse_url($url, PHP_URL_HOST);
        $desc = $description ?? 'Getfy ('.($host ?: 'webhook').')';

        try {
            $response = $this->httpForCredentials($credentials)
                ->post('/api/webhooks/endpoints/register', [
                    'url' => $url,
                    'description' => $desc,
                    'event_types' => ['checkout.payment.*', 'pix.payment.*', 'payout.*', 'pix_parcelado.*', 'subscription.*'],
                    'rotate_if_exists' => $rotateIfExists,
                ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('CajuPay: falha ao contatar o registro de webhooks: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $msg = $response->body();
            if (strlen($msg) > 300) {
                $msg = substr($msg, 0, 300).'…';
            }
            if ($response->status() === 403) {
                throw new \RuntimeException('CajuPay: permissão negada (webhooks.write). Verifique as permissões da chave de API no painel CajuPay.');
            }
            throw new \RuntimeException('CajuPay: '.($msg !== '' ? $msg : 'Erro ao registrar webhook.'));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay: resposta inválida ao registrar webhook.');
        }

        $endpoint = is_array($data['endpoint'] ?? null) ? $data['endpoint'] : [];
        $endpointId = $endpoint['id'] ?? ($data['id'] ?? null);
        $signingSecret = $data['signing_secret'] ?? null;

        if (! is_string($endpointId) || $endpointId === '') {
            throw new \RuntimeException('CajuPay: endpoint_id ausente na resposta de webhook.');
        }

        return [
            'endpoint_id' => $endpointId,
            'signing_secret' => is_string($signingSecret) && $signingSecret !== '' ? $signingSecret : null,
            'created' => (bool) ($data['created'] ?? false),
            'already_exists' => (bool) ($data['already_exists'] ?? false),
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    public function getWebhookSetupStatus(array $credentials): ?array
    {
        if (! $this->hasApiKeys($credentials)) {
            return null;
        }

        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/webhooks/setup-status');

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver getWebhookSetupStatus', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function normalizePaymentStatus(mixed $status): ?string
    {
        if (! is_string($status) || trim($status) === '') {
            return null;
        }
        $s = strtolower(trim($status));
        // Estados terminais de sucesso (PSP/Stripe-like + nomes internos possíveis)
        if (in_array($s, [
            'paid',
            'completed',
            'complete',
            'settled',
            'approved',
            'confirmado',
            'confirmed',
            'success',
            'successful',
            'succeeded',
            'done',
            'captured',
            'capture_succeeded',
            'charge_succeeded',
            'payment_succeeded',
            'payment_completed',
            'checkout_completed',
            'authorized',
            'authorised',
            'paid_out',
            'pago',
            'aprovado',
        ], true)) {
            return 'paid';
        }
        if (in_array($s, ['pending', 'processing', 'waiting', 'requires_action', 'requires_payment_method', 'open'], true)) {
            return 'pending';
        }
        if (in_array($s, ['cancelled', 'canceled', 'expired', 'failed', 'refunded', 'rejected', 'refused'], true)) {
            return 'cancelled';
        }

        return $s;
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        // CajuPay card flow runs through the embedded SDK on the checkout page
        // (see CheckoutController::cajupaySession). It is not invoked through
        // PaymentService server-side card path.
        throw new \RuntimeException('CajuPay: cartão é processado via SDK no checkout (use o fluxo embedded).');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('CajuPay: boleto não está disponível nesta integração.');
    }

    /**
     * Resolve CajuPay payment_id (UUID) for refund API.
     * Prefer UUID persistido; valida via GET /api/payments/{id} quando há API keys.
     */
    public function resolvePaymentIdForOrder(Order $order): ?string
    {
        if ($order->gateway !== 'cajupay') {
            return null;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $sessionId = trim((string) ($meta['cajupay_checkout_session_id'] ?? ''));
        $gatewayId = trim((string) ($order->gateway_id ?? ''));

        $candidates = [];
        foreach (['cajupay_payment_id', 'payment_id'] as $key) {
            $stored = $meta[$key] ?? null;
            if (is_string($stored) && $this->looksLikeUuid(trim($stored))) {
                $candidates[] = trim($stored);
            }
        }
        if ($gatewayId !== '' && $this->looksLikeUuid($gatewayId) && $gatewayId !== $sessionId) {
            $candidates[] = $gatewayId;
        }
        if ($gatewayId !== '' && $this->looksLikeUuid($gatewayId) && $sessionId === '' && ! in_array($gatewayId, $candidates, true)) {
            $candidates[] = $gatewayId;
        }
        $candidates = array_values(array_unique($candidates));

        $credential = GatewayCredential::forTenant($order->tenant_id)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();

        if (! $credential) {
            return $candidates[0] ?? null;
        }

        $credentials = $credential->getDecryptedCredentials();
        if (! $this->hasApiKeys($credentials)) {
            return $candidates[0] ?? null;
        }

        foreach ($candidates as $candidate) {
            $payment = $this->fetchPaymentById($candidate, $credentials);
            if ($payment === null) {
                continue;
            }
            $pid = $this->extractPaymentIdFromPaymentPayload($payment) ?? $candidate;
            if ($this->looksLikeUuid($pid)) {
                return $pid;
            }
        }

        $sessionToken = trim((string) ($meta['cajupay_session_token'] ?? ''));
        foreach (array_values(array_unique(array_filter([$sessionId, $sessionToken, $gatewayId]))) as $sessionCandidate) {
            if ($sessionCandidate === '' || (! $this->looksLikeUuid($sessionCandidate) && ! $this->looksLikeSdkSessionToken($sessionCandidate))) {
                continue;
            }
            $fromSession = $this->extractPaymentIdFromPublicSdkSession($sessionCandidate, $credentials);
            if ($fromSession !== null) {
                $payment = $this->fetchPaymentById($fromSession, $credentials);
                if ($payment !== null) {
                    return $this->extractPaymentIdFromPaymentPayload($payment) ?? $fromSession;
                }

                return $fromSession;
            }
        }

        $lookupIds = array_values(array_unique(array_filter([$gatewayId, $sessionId, ...$candidates])));
        foreach ($lookupIds as $lookupId) {
            if ($lookupId === '' || ! $this->looksLikeUuid($lookupId)) {
                continue;
            }
            $resolved = $this->findPaymentIdInList($lookupId, $credentials, (int) $order->id);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->findPaymentIdInList('', $credentials, (int) $order->id);
    }

    /**
     * Extrai payment_id (UUID) da sessão pública do checkout SDK.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function extractPaymentIdFromPublicSdkSession(string $sessionToken, array $credentials = []): ?string
    {
        if ($sessionToken === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withOptions(['connect_timeout' => 10])
                ->baseUrl($this->baseUrl($credentials))
                ->get('/api/sdk/public/checkout/sessions/'.urlencode($sessionToken));

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            return $this->extractPaymentIdFromSessionPayload($data);
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver extractPaymentIdFromPublicSdkSession', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractPaymentIdFromSessionPayload(array $data): ?string
    {
        foreach (['payment_id', 'charge_id', 'cajupay_charge_id', 'cajupay_payment_id'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '' && $this->looksLikeUuid($value)) {
                return $value;
            }
        }

        foreach (['payment', 'latest_payment', 'charge', 'latest_charge'] as $nest) {
            $obj = $data[$nest] ?? null;
            if (! is_array($obj)) {
                continue;
            }
            foreach (['payment_id', 'charge_id', 'id'] as $key) {
                $value = $obj[$key] ?? null;
                if (is_string($value) && $value !== '' && $this->looksLikeUuid($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function findPaymentIdInList(string $transactionId, array $credentials, ?int $orderId = null): ?string
    {
        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/payments', ['limit' => 100]);

            if (! $response->successful()) {
                return null;
            }

            $list = $this->normalizePaymentsList($response->json());

            foreach ($list as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $pid = $item['payment_id'] ?? $item['id'] ?? null;
                if (! is_string($pid) || $pid === '' || ! $this->looksLikeUuid($pid)) {
                    continue;
                }
                if ($transactionId !== '' && $pid === $transactionId) {
                    return $pid;
                }
                $session = $item['checkout_session_id'] ?? null;
                if ($transactionId !== '' && is_string($session) && $session === $transactionId) {
                    return $pid;
                }
                if ($orderId !== null && $orderId > 0) {
                    $customerRef = (string) ($item['customer_ref'] ?? '');
                    $productRef = (string) ($item['product_ref'] ?? '');
                    if ($customerRef === 'getfy-order-'.$orderId || $productRef === 'order-'.$orderId) {
                        return $pid;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver findPaymentIdInList', ['message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @param  mixed  $payload
     * @return list<array<string, mixed>>
     */
    private function normalizePaymentsList(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (isset($payload['items']) && is_array($payload['items'])) {
            $payload = $payload['items'];
        } elseif (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        return array_values(array_filter($payload, static fn ($it) => is_array($it)));
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    private function fetchPaymentById(string $paymentId, array $credentials): ?array
    {
        if ($paymentId === '' || ! $this->looksLikeUuid($paymentId)) {
            return null;
        }

        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/payments/'.urlencode($paymentId));

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver fetchPaymentById', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractPaymentIdFromPaymentPayload(array $data): ?string
    {
        foreach (['payment_id', 'id', 'cajupay_payment_id'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '' && $this->looksLikeUuid($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function createPixRefund(string $paymentId, array $credentials, ?string $clientRefundId = null): array
    {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API para reembolso PIX.');
        }

        if (! $this->looksLikeUuid($paymentId)) {
            throw new \RuntimeException('CajuPay reembolso: payment_id inválido (esperado UUID CajuPay).');
        }

        $body = [];
        if ($clientRefundId !== null && $clientRefundId !== '') {
            $body['client_refund_id'] = $clientRefundId;
        }

        $response = $this->httpForCredentials($credentials)
            ->post('/api/payments/'.urlencode($paymentId).'/pix-refund', $body);

        if (! $response->successful()) {
            $errorCode = $this->extractApiErrorCode((string) $response->body());
            // Pedido já existe em failed/pending_balance — retoma via retry (módulo 18).
            if ($response->status() === 409
                || str_starts_with($errorCode, 'refund_not_eligible')
                || $errorCode === 'refund_failed') {
                $existing = $this->getPixRefund($paymentId, $credentials);
                $existingStatus = is_array($existing) ? strtolower((string) ($existing['status'] ?? '')) : '';
                if (in_array($existingStatus, ['failed', 'pending_balance'], true)) {
                    return $this->retryPixRefund($paymentId, $credentials);
                }
            }

            throw new \RuntimeException('CajuPay reembolso: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao solicitar reembolso PIX.'
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay reembolso: resposta inválida.');
        }

        $status = strtolower((string) ($data['status'] ?? ''));
        if ($status === 'failed') {
            try {
                $retried = $this->retryPixRefund($paymentId, $credentials);
                $retryStatus = strtolower((string) ($retried['status'] ?? ''));
                if ($retryStatus !== 'failed') {
                    return $retried;
                }
                $data = $retried;
            } catch (\Throwable $e) {
                Log::debug('CajuPayDriver createPixRefund retry após failed', ['message' => $e->getMessage()]);
            }

            $lastError = trim((string) ($data['last_error'] ?? ''));
            throw new \RuntimeException(
                'CajuPay reembolso: '.($lastError !== '' ? $lastError : 'Falha ao enviar reembolso ao provedor (status failed).')
            );
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function retryPixRefund(string $paymentId, array $credentials): array
    {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API para reembolso PIX.');
        }

        $response = $this->httpForCredentials($credentials)
            ->post('/api/payments/'.urlencode($paymentId).'/pix-refund/retry');

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay reembolso: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao retentar reembolso PIX.'
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay reembolso: resposta inválida no retry.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    public function getPixRefund(string $paymentId, array $credentials): ?array
    {
        if (! $this->hasApiKeys($credentials)) {
            return null;
        }

        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/payments/'.urlencode($paymentId).'/pix-refund');

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::debug('CajuPayDriver getPixRefund', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function normalizeDocument(string $document): string
    {
        $digits = preg_replace('/\D/', '', $document);
        $digits = is_string($digits) ? $digits : '';

        if (strlen($digits) === 11 || strlen($digits) === 14) {
            return $digits;
        }

        return '00000000000';
    }

    /**
     * Normaliza telefone para E.164 (+5511999999999). Retorna null se vazio ou inválido.
     */
    private function normalizePhoneForCajuPay(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if (! is_string($digits) || strlen($digits) < 8) {
            return null;
        }

        return '+'.$digits;
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: '';
        $name = trim($name);
        if ($name === '') {
            return 'Cliente';
        }
        if (strlen($name) > 120) {
            return substr($name, 0, 120);
        }

        return $name;
    }

    private function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        $email = preg_replace('/[\x00-\x1F\x7F]/u', '', $email) ?: '';
        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function getWalletBalance(array $credentials, string $kind = 'main'): array
    {
        $response = $this->httpForCredentials($credentials)
            ->get('/api/wallet/balance', ['kind' => $kind]);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay wallet balance: '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return list<array<string, mixed>>
     */
    public function getWalletEntries(array $credentials, string $kind = 'main', int $limit = 50): array
    {
        $response = $this->httpForCredentials($credentials)
            ->get('/api/wallet/entries', ['kind' => $kind, 'limit' => $limit]);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay wallet entries: '.$response->body());
        }

        $data = $response->json();
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function createSplit(array $credentials, string $name, int $percentBps): string
    {
        $response = $this->httpForCredentials($credentials)->post('/api/splits', [
            'name' => $name,
            'percent_bps' => $percentBps,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay create split: '.$response->body());
        }

        $id = $response->json('id') ?? $response->json('data.id');
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('CajuPay create split: ID ausente na resposta.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function updateSplit(array $credentials, string $splitId, string $name, int $percentBps): void
    {
        $response = $this->httpForCredentials($credentials)->put('/api/splits/'.$splitId, [
            'name' => $name,
            'percent_bps' => $percentBps,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay update split: '.$response->body());
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function createPayout(
        array $credentials,
        int $amountCents,
        string $pixKey,
        string $pixKeyType,
        string $idempotencyKey,
        ?string $keyOwnerDocument = null,
    ): array {
        $pixKeyType = strtolower($pixKeyType);
        if ($pixKeyType === 'random') {
            $pixKeyType = 'evp';
        }

        $body = [
            'amount_cents' => $amountCents,
            'currency' => 'BRL',
            'wallet_kind' => 'main',
            'destination' => ['method' => 'dict'],
            'pix_key' => $pixKey,
            'pix_key_type' => $pixKeyType,
        ];

        $document = $keyOwnerDocument ?? '';
        if ($document === '' && in_array($pixKeyType, ['cpf', 'cnpj'], true)) {
            $document = preg_replace('/\D/', '', $pixKey);
        }
        if ($document !== '') {
            $body['key_owner_document'] = preg_replace('/\D/', '', $document);
        }

        $response = $this->httpForCredentials($credentials)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/api/payouts', $body);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay payout: '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function getPayout(array $credentials, string $payoutId): array
    {
        $response = $this->httpForCredentials($credentials)
            ->get('/api/payouts/'.$payoutId);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay get payout: '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return list<array<string, mixed>>
     */
    public function listPayouts(array $credentials, int $limit = 50): array
    {
        $response = $this->httpForCredentials($credentials)
            ->get('/api/payouts', ['limit' => $limit]);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay list payouts: '.$response->body());
        }

        $data = $response->json();
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function normalizePayoutStatus(array $response): string
    {
        $status = strtolower($this->extractPayoutStatus($response));

        return match (true) {
            in_array($status, ['paid', 'completed', 'success', 'succeeded', 'approved', 'done', 'settled'], true) => 'paid',
            in_array($status, ['failed', 'error', 'rejected'], true) => 'failed',
            in_array($status, ['cancelled', 'canceled'], true) => 'cancelled',
            in_array($status, ['pending', 'processing', 'in_transit', 'in_progress'], true) => 'pending',
            default => $status !== '' ? $status : 'pending',
        };
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function extractPayoutStatus(array $response): string
    {
        $candidates = [
            $response['status'] ?? null,
            $response['payout_status'] ?? null,
            is_array($response['data'] ?? null) ? ($response['data']['status'] ?? $response['data']['payout_status'] ?? null) : null,
            is_array($response['data']['object'] ?? null) ? ($response['data']['object']['status'] ?? null) : null,
            is_array($response['payout'] ?? null) ? ($response['payout']['status'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    public function extractPayoutId(array $response): ?string
    {
        $candidates = [
            $response['id'] ?? null,
            $response['payout_id'] ?? null,
            $response['cajupay_payout_id'] ?? null,
            is_array($response['data'] ?? null) ? ($response['data']['id'] ?? $response['data']['payout_id'] ?? null) : null,
            is_array($response['data']['object'] ?? null) ? ($response['data']['object']['id'] ?? null) : null,
            is_array($response['payout'] ?? null) ? ($response['payout']['id'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function getPixParceladoEnrollment(array $credentials): array
    {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API.');
        }

        $response = $this->httpForCredentials($credentials)->get('/api/pix-parcelado/enrollment');
        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay PIX Parcelado: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao consultar adesão PIX Parcelado.'
            ));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function acceptPixParceladoEnrollment(array $credentials): array
    {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API.');
        }

        $response = $this->httpForCredentials($credentials)->post('/api/pix-parcelado/enroll/accept');
        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay PIX Parcelado: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao aceitar contrato PIX Parcelado.'
            ));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function getPixParceladoPlatformRules(array $credentials): array
    {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API.');
        }

        $response = $this->httpForCredentials($credentials)->get('/api/pix-parcelado/platform-rules');
        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay PIX Parcelado: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao consultar regras da plataforma.'
            ));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getPixParceladoPublicConfig(string $payAccountId, array $credentials = []): array
    {
        $payAccountId = trim($payAccountId);
        if ($payAccountId === '') {
            throw new \RuntimeException('CajuPay: pay_account_id ausente.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->withOptions(['connect_timeout' => 10])
            ->baseUrl($this->baseUrl($credentials))
            ->get('/api/pix-parcelado/public/config/'.urlencode($payAccountId));

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay PIX Parcelado: config pública indisponível.');
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function resolvePayAccountId(array $credentials): ?string
    {
        $cached = trim((string) ($credentials['pay_account_id'] ?? ''));
        if ($cached !== '' && $this->looksLikeUuid($cached)) {
            return $cached;
        }

        if (! $this->hasApiKeys($credentials)) {
            return null;
        }

        try {
            $response = $this->httpForCredentials($credentials)->get('/api/payment-links', ['limit' => 1]);
            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            $first = $data[0] ?? null;
            if (is_array($first)) {
                $id = $this->extractPayAccountId($first);
                if ($id !== null) {
                    return $id;
                }
            }

            return $this->extractPayAccountId($data);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createPaymentLink(array $credentials, array $body): array
    {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API.');
        }

        $response = $this->httpForCredentials($credentials)->post('/api/payment-links', $body);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay link de pagamento: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao criar link de pagamento.'
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay link de pagamento: resposta inválida.');
        }

        return $data;
    }

    public function createPixParceladoPlan(array $credentials, array $body, string $idempotencyKey): array
    {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API.');
        }

        $response = $this->httpForCredentials($credentials)
            ->withHeaders(['Idempotency-Key' => Str::limit($idempotencyKey, 200, '')])
            ->post('/api/pix-parcelado/plans', $body);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay PIX Parcelado: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao criar plano PIX Parcelado.'
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay PIX Parcelado: resposta inválida.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function getPixParceladoPlan(string $planId, array $credentials): array
    {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API.');
        }

        $response = $this->httpForCredentials($credentials)
            ->get('/api/pix-parcelado/plans/'.urlencode($planId));

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay PIX Parcelado: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao consultar plano.'
            ));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractPayAccountId(array $data): ?string
    {
        foreach (['pay_account_id', 'payAccountId', 'account_id'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '' && $this->looksLikeUuid($value)) {
                return $value;
            }
        }

        foreach (['account', 'wallet', 'enrollment'] as $nest) {
            $obj = $data[$nest] ?? null;
            if (! is_array($obj)) {
                continue;
            }
            foreach (['pay_account_id', 'id'] as $key) {
                $value = $obj[$key] ?? null;
                if (is_string($value) && $value !== '' && $this->looksLikeUuid($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $credentials
     */
    public function injectSplitIdIntoBody(array &$body, array $credentials, ?string $splitId): void
    {
        if ($splitId) {
            $body['split_id'] = $splitId;
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createSubscription(array $credentials, array $body, string $idempotencyKey): array
    {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure as chaves de API para assinaturas.');
        }

        $response = $this->httpForCredentials($credentials)
            ->withHeaders(['Idempotency-Key' => Str::limit($idempotencyKey, 200, '')])
            ->post('/api/subscriptions', $body);

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay assinatura: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao criar assinatura PIX Automático.'
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay assinatura: resposta inválida.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function cancelSubscription(array $credentials, string $subscriptionId): array
    {
        $response = $this->httpForCredentials($credentials)
            ->post('/api/subscriptions/'.urlencode($subscriptionId).'/cancel');

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay assinatura: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao cancelar assinatura.'
            ));
        }

        $data = $response->json();

        return is_array($data) ? $data : ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function getSubscription(array $credentials, string $subscriptionId): array
    {
        $response = $this->httpForCredentials($credentials)
            ->get('/api/subscriptions/'.urlencode($subscriptionId));

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay assinatura: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao consultar assinatura.'
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('CajuPay assinatura: resposta inválida.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return list<array<string, mixed>>
     */
    public function listSubscriptionCharges(array $credentials, string $subscriptionId): array
    {
        $response = $this->httpForCredentials($credentials)
            ->get('/api/subscriptions/'.urlencode($subscriptionId).'/charges');

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay assinatura: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao listar cobranças.'
            ));
        }

        $data = $response->json();
        if (! is_array($data)) {
            return [];
        }

        if (isset($data['items']) && is_array($data['items'])) {
            $data = $data['items'];
        } elseif (isset($data['charges']) && is_array($data['charges'])) {
            $data = $data['charges'];
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return array_values(array_filter($data, static fn ($it) => is_array($it)));
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function syncSubscription(array $credentials, string $subscriptionId): array
    {
        $response = $this->httpForCredentials($credentials)
            ->post('/api/subscriptions/'.urlencode($subscriptionId).'/sync');

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay assinatura: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao sincronizar assinatura.'
            ));
        }

        $data = $response->json();

        return is_array($data) ? $data : ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function retrySubscriptionCharge(array $credentials, string $subscriptionId, string $chargeId): array
    {
        $response = $this->httpForCredentials($credentials)
            ->post('/api/subscriptions/'.urlencode($subscriptionId).'/charges/'.urlencode($chargeId).'/retry');

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay assinatura: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao retentar cobrança.'
            ));
        }

        $data = $response->json();

        return is_array($data) ? $data : ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function refundSubscriptionCharge(array $credentials, string $subscriptionId, string $chargeId): array
    {
        $response = $this->httpForCredentials($credentials)
            ->post('/api/subscriptions/'.urlencode($subscriptionId).'/charges/'.urlencode($chargeId).'/refund');

        if (! $response->successful()) {
            throw new \RuntimeException('CajuPay assinatura: '.$this->formatApiErrorMessage(
                (string) $response->body(),
                'Erro ao reembolsar cobrança.'
            ));
        }

        $data = $response->json();

        return is_array($data) ? $data : ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function applyPartnerCheckoutUrl(array &$body, ?string $partnerCheckoutUrl): void
    {
        $url = trim((string) $partnerCheckoutUrl);
        if ($url === '') {
            return;
        }

        $body['partner_checkout_url'] = $url;
    }

    private function extractApiErrorCode(string $body): string
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return '';
        }

        return strtolower(trim((string) ($decoded['error'] ?? '')));
    }

    private function formatApiErrorMessage(string $body, string $fallback): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $error = strtolower(trim((string) ($decoded['error'] ?? '')));
            $friendly = match (true) {
                $error === 'https_required' => 'A URL do checkout deve usar HTTPS. Verifique APP_URL no servidor.',
                $error === 'invalid_partner_checkout_url' => 'URL do checkout inválida. Verifique APP_URL e o slug do produto.',
                $error === 'unauthorized' => 'Credenciais CajuPay inválidas. Reconecte o gateway em Integrações.',
                $error === 'forbidden' => 'A chave CajuPay precisa do escopo adequado (payments.write / subscriptions.write).',
                $error === 'payment_not_found' => 'Pagamento não encontrado na CajuPay (UUID inexistente ou de outra conta).',
                $error === 'invalid_payment_id' => 'O ID do pagamento não é um UUID CajuPay válido.',
                $error === 'payment_not_paid' => 'O pagamento ainda não está como pago na CajuPay.',
                $error === 'refund_window_expired' => 'Prazo de reembolso PIX expirado (até 30 dias após o pagamento).',
                $error === 'med_blocks_refund' => 'Há uma disputa MED aberta neste pagamento. Resolva a MED antes de reembolsar.',
                $error === 'invalid_client_refund_id' => 'Identificador interno do reembolso inválido.',
                $error === 'missing_pix_end_to_end_id' => 'Falta o identificador E2E do PIX no provedor. Aguarde a liquidação ou contate o suporte CajuPay.',
                $error === 'refund_only_onlyup' => 'Este PIX não permite reembolso via API (provedor diferente de OnlyUp). Estorne no painel CajuPay.',
                $error === 'onlyup_account_missing' => 'Conta OnlyUp não vinculada a este pagamento. Verifique a conta na CajuPay.',
                $error === 'refund_not_found' => 'Não há pedido de reembolso prévio para este pagamento.',
                $error === 'refund_failed' => 'A CajuPay não conseguiu processar o reembolso. Tente novamente em instantes.',
                $error === 'refund_cancelled' => 'O pedido de reembolso foi cancelado na CajuPay.',
                $error === 'rate_limited' => 'Limite de requisições da CajuPay atingido. Tente novamente em instantes.',
                $error === 'missing_pix_authorization_payload' => 'A CajuPay não retornou o QR de autorização. Tente novamente.',
                $error === 'idempotency_key_reuse_mismatch' => 'Chave de idempotência reutilizada com payload diferente. Gere um novo checkout.',
                str_starts_with($error, 'refund_not_eligible') => 'Este pagamento não está elegível para reembolso no momento ('.$error.').',
                default => '',
            };
            if ($friendly !== '') {
                return $friendly;
            }

            $message = trim((string) ($decoded['message'] ?? $decoded['error'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        $msg = trim($body);
        if ($msg === '') {
            return $fallback;
        }
        if (strlen($msg) > 300) {
            $msg = substr($msg, 0, 300).'…';
        }

        return $msg;
    }

    /**
     * Merchant Card API (Cartão Brasil) — paths documentados em /v1/card/*
     * com fallback para /api/card/* se a conta responder 404 no prefixo v1.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createCardCharge(array $credentials, array $body, string $idempotencyKey): array
    {
        return $this->cardApiRequest($credentials, 'POST', '/charges', $body, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function confirmCardCharge(array $credentials, string $chargeId, array $body): array
    {
        $chargeId = trim($chargeId);
        if ($chargeId === '') {
            throw new \RuntimeException('CajuPay cartão: charge id ausente.');
        }

        return $this->cardApiRequest($credentials, 'POST', '/charges/'.rawurlencode($chargeId).'/confirm', $body);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function getCardCharge(array $credentials, string $chargeId): array
    {
        $chargeId = trim($chargeId);
        if ($chargeId === '') {
            throw new \RuntimeException('CajuPay cartão: charge id ausente.');
        }

        return $this->cardApiRequest($credentials, 'GET', '/charges/'.rawurlencode($chargeId));
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{options: list<array<string, mixed>>, raw: array<string, mixed>}
     */
    public function getCardInstallmentOptions(array $credentials, int $amountCents, ?int $maxInstallments = null): array
    {
        if ($amountCents < 1) {
            throw new \RuntimeException('CajuPay cartão: amount_cents inválido.');
        }

        $query = ['amount_cents' => $amountCents];
        if ($maxInstallments !== null && $maxInstallments >= 1) {
            $query['max_installments'] = min(12, $maxInstallments);
        }

        $data = $this->cardApiRequest($credentials, 'GET', '/installment-options', $query);
        $options = $data['installment_options'] ?? ($data['options'] ?? $data);
        if (! is_array($options)) {
            $options = [];
        }

        return [
            'options' => array_values(array_filter($options, static fn ($row) => is_array($row))),
            'raw' => $data,
        ];
    }

    /**
     * Cobrança com cartão já salvo (card_token).
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    public function createCardChargeWithToken(
        array $credentials,
        int $amountCents,
        string $currency,
        string $cardToken,
        array $customer,
        string $idempotencyKey,
        int $installments = 1,
        ?string $description = null,
    ): array {
        $cardToken = trim($cardToken);
        if ($cardToken === '') {
            throw new \RuntimeException('CajuPay cartão: card_token ausente.');
        }

        $body = [
            'amount' => $amountCents,
            'currency' => MoneyMinorUnits::normalizeCurrencyCode($currency),
            'payment_type' => 'credit',
            'installments' => max(1, min(12, $installments)),
            'card_token' => $cardToken,
            'customer' => array_filter([
                'name' => trim((string) ($customer['name'] ?? '')),
                'email' => trim((string) ($customer['email'] ?? '')),
                'document' => preg_replace('/\D/', '', (string) ($customer['document'] ?? '')) ?: null,
            ], static fn ($v) => $v !== null && $v !== ''),
        ];
        if (is_string($description) && trim($description) !== '') {
            $body['description'] = trim($description);
        }

        return $this->createCardCharge($credentials, $body, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $bodyOrQuery
     * @return array<string, mixed>
     */
    private function cardApiRequest(
        array $credentials,
        string $method,
        string $pathSuffix,
        ?array $bodyOrQuery = null,
        ?string $idempotencyKey = null,
    ): array {
        if (! $this->hasApiKeys($credentials)) {
            throw new \RuntimeException('CajuPay: configure a chave pública e a chave secreta da API (painel CajuPay → API / Chaves).');
        }

        $prefixes = ['/v1/card', '/api/card'];
        $lastError = null;

        foreach ($prefixes as $prefix) {
            $path = $prefix.$pathSuffix;
            try {
                $pending = $this->httpForCredentials($credentials);
                if ($idempotencyKey !== null && $idempotencyKey !== '') {
                    $pending = $pending->withHeaders([
                        'Idempotency-Key' => Str::limit($idempotencyKey, 200, ''),
                    ]);
                }

                $response = match (strtoupper($method)) {
                    'GET' => $pending->get($path, is_array($bodyOrQuery) ? $bodyOrQuery : []),
                    'POST' => $pending->post($path, is_array($bodyOrQuery) ? $bodyOrQuery : []),
                    default => throw new \InvalidArgumentException('CajuPay cartão: método HTTP inválido.'),
                };

                if ($response->status() === 404 && $prefix === '/v1/card') {
                    $lastError = '404';
                    continue;
                }

                if (! $response->successful()) {
                    throw new \RuntimeException('CajuPay cartão: '.$this->formatApiErrorMessage(
                        (string) $response->body(),
                        'Erro na API de cartão.'
                    ));
                }

                $data = $response->json();
                if (! is_array($data)) {
                    throw new \RuntimeException('CajuPay cartão: resposta inválida.');
                }

                return $data;
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if ($prefix === '/v1/card') {
                    continue;
                }
                throw new \RuntimeException('CajuPay cartão: '.$e->getMessage(), 0, $e);
            }
        }

        throw new \RuntimeException('CajuPay cartão: endpoint não encontrado'.($lastError ? " ({$lastError})" : '').'.');
    }
}
