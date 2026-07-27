<?php

namespace App\Gateways\Pagarme;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PagarmeDriver implements GatewayDriver
{
    private function baseUrl(): string
    {
        $url = (string) config('services.pagarme.base_url', 'https://api.pagar.me/core/v5');

        return rtrim($url, '/');
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function secretKey(array $credentials): string
    {
        return trim($credentials['secret_key'] ?? '');
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function http(array $credentials, int $timeout = 25): \Illuminate\Http\Client\PendingRequest
    {
        $secret = $this->secretKey($credentials);
        if ($secret === '') {
            throw new \RuntimeException('Pagar.me: Secret Key não configurada.');
        }

        return Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withHeaders([
                'User-Agent' => config('app.name', 'Checkout').'/Pagarme',
            ])
            ->withOptions(['connect_timeout' => min(60, max(2, (int) ceil($timeout / 4)))]);
    }

    /**
     * @param  array{name: string, document: string, email: string, phone?: string, address?: array}  $consumer
     * @return array<string, mixed>
     */
    private function buildCustomer(array $consumer): array
    {
        $name = trim((string) ($consumer['name'] ?? ''));
        if ($name === '') {
            $name = 'Cliente';
        }
        if (strlen($name) > 64) {
            $name = substr($name, 0, 64);
        }

        $email = trim((string) ($consumer['email'] ?? ''));
        if ($email === '' || ! str_contains($email, '@')) {
            throw new \RuntimeException('Pagar.me: e-mail do cliente é obrigatório.');
        }
        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? ''));
        if (strlen($document) < 11) {
            $document = '00000000000';
        }

        $isCompany = strlen($document) === 14;
        $customer = [
            'name' => $name,
            'email' => substr($email, 0, 64),
            'type' => $isCompany ? 'company' : 'individual',
            'document' => $document,
            'document_type' => $isCompany ? 'CNPJ' : 'CPF',
        ];

        $phones = $this->phonesFromConsumer($consumer['phone'] ?? '');
        if ($phones !== null) {
            $customer['phones'] = $phones;
        } else {
            $customer['phones'] = [
                'mobile_phone' => [
                    'country_code' => '55',
                    'area_code' => '11',
                    'number' => '999999999',
                ],
            ];
        }

        $address = $consumer['address'] ?? null;
        if (is_array($address) && ! empty($address['zip_code'])) {
            $zip = preg_replace('/\D/', '', (string) ($address['zip_code'] ?? ''));
            if (strlen($zip) > 8) {
                $zip = substr($zip, 0, 8);
            }
            $street = trim((string) ($address['street_name'] ?? ''));
            $num = trim((string) ($address['street_number'] ?? ''));
            $neigh = trim((string) ($address['neighborhood'] ?? ''));
            $line1 = trim($num.', '.$street.', '.$neigh, ' ,');
            if ($line1 !== '') {
                $customer['address'] = array_filter([
                    'line_1' => strlen($line1) > 256 ? substr($line1, 0, 256) : $line1,
                    'line_2' => isset($address['complement']) ? substr(trim((string) $address['complement']), 0, 128) : null,
                    'zip_code' => $zip,
                    'city' => substr(trim((string) ($address['city'] ?? '')), 0, 64),
                    'state' => strtoupper(substr(trim((string) ($address['federal_unit'] ?? '')), 0, 2)),
                    'country' => 'BR',
                ], fn ($v) => $v !== null && $v !== '');
            }
        }

        return $customer;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function phonesFromConsumer(string $phone): ?array
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (! is_string($digits) || $digits === '') {
            return null;
        }
        if (strlen($digits) > 11 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');
        if (strlen($digits) < 10) {
            return null;
        }
        if (strlen($digits) > 11) {
            $digits = substr($digits, -11);
        }
        if (strlen($digits) === 10) {
            $digits = substr($digits, 0, 2).'9'.substr($digits, 2);
        }
        $area = substr($digits, 0, 2);
        $number = substr($digits, 2);

        return [
            'mobile_phone' => [
                'country_code' => '55',
                'area_code' => $area,
                'number' => $number,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $customer  Obrigatório se $customerId for nulo.
     * @return array{transaction_id: string, charge: array<string, mixed>, order?: array<string, mixed>}
     */
    private function createOrder(
        array $credentials,
        int $amountCents,
        ?array $customer,
        array $payment,
        string $externalId,
        ?string $idempotencyKey = null,
        ?string $customerId = null,
        ?string $orderCodeOverride = null,
        ?string $currency = null
    ): array {
        if ($amountCents < 1) {
            throw new \RuntimeException('Pagar.me: valor inválido.');
        }

        $trimmedOverride = $orderCodeOverride !== null ? trim($orderCodeOverride) : '';
        if ($trimmedOverride !== '') {
            $code = strlen($trimmedOverride) > 52 ? substr($trimmedOverride, 0, 52) : $trimmedOverride;
        } else {
            $code = 'ord_'.preg_replace('/\W/', '', $externalId);
            if (strlen($code) > 52) {
                $code = substr($code, 0, 52);
            }
        }

        $body = [
            'code' => $code,
            'items' => [
                [
                    'amount' => $amountCents,
                    'description' => 'Pedido #'.$externalId,
                    'quantity' => 1,
                    'code' => 'item_'.$externalId,
                ],
            ],
            'payments' => [$payment],
            'closed' => true,
        ];
        $trimmedCustomerId = $customerId !== null ? trim($customerId) : '';
        if ($trimmedCustomerId !== '') {
            $body['customer_id'] = $trimmedCustomerId;
        } else {
            if ($customer === null || $customer === []) {
                throw new \RuntimeException('Pagar.me: dados do cliente obrigatórios.');
            }
            $body['customer'] = $customer;
        }

        $trimmedCurrency = $currency !== null ? trim($currency) : '';
        if ($trimmedCurrency !== '' && preg_match('/^[A-Za-z]{3}$/', $trimmedCurrency)) {
            $body['currency'] = strtoupper($trimmedCurrency);
        }

        $req = $this->http($credentials, 45)->withHeaders(array_filter([
            'Idempotency-Key' => $idempotencyKey !== null && $idempotencyKey !== ''
                ? substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $idempotencyKey), 0, 64)
                : null,
        ]));

        $response = $req->post($this->baseUrl().'/orders', $body);

        if (! $response->successful()) {
            $this->throwApiError($response, $externalId);
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('Pagar.me: resposta inválida.');
        }

        $charges = $data['charges'] ?? [];
        if (! is_array($charges) || $charges === []) {
            throw new \RuntimeException('Pagar.me: pedido sem cobrança.');
        }

        $charge = $charges[0];
        if (! is_array($charge)) {
            throw new \RuntimeException('Pagar.me: cobrança inválida.');
        }

        $chargeId = $charge['id'] ?? null;
        if (! is_string($chargeId) || $chargeId === '') {
            throw new \RuntimeException('Pagar.me: resposta sem id da cobrança.');
        }

        return [
            'transaction_id' => $chargeId,
            'charge' => $charge,
            'order' => $data,
        ];
    }

    private function throwApiError(\Illuminate\Http\Client\Response $response, string $externalId): void
    {
        $json = $response->json();
        $msg = 'Não foi possível processar o pagamento.';
        if (is_array($json)) {
            $errors = $json['errors'] ?? $json['message'] ?? null;
            if (is_string($errors)) {
                $msg = $errors;
            } elseif (is_array($errors) && $errors !== []) {
                $first = $errors[0] ?? null;
                if (is_array($first)) {
                    $msg = (string) ($first['message'] ?? $first['description'] ?? json_encode($first));
                } else {
                    $msg = json_encode($errors);
                }
            }
        }
        Log::warning('PagarmeDriver API error', [
            'order_id' => $externalId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        throw new \RuntimeException('Pagar.me: '.$msg);
    }

    public function testConnection(array $credentials): bool
    {
        if ($this->secretKey($credentials) === '') {
            return false;
        }
        try {
            $response = $this->http($credentials, 15)->get($this->baseUrl().'/charges', ['size' => 1]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::debug('PagarmeDriver testConnection', ['message' => $e->getMessage()]);

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
        $amountCents = (int) max(1, (int) round($amount * 100));
        $customer = $this->buildCustomer($consumer);

        $payment = [
            'payment_method' => 'pix',
            'amount' => $amountCents,
            'pix' => [
                'expires_in' => 86400,
            ],
        ];

        $trimmedPostback = trim($postbackUrl);
        if ($trimmedPostback !== '' && filter_var($trimmedPostback, FILTER_VALIDATE_URL)
            && stripos($trimmedPostback, 'localhost') === false
            && stripos($trimmedPostback, '127.0.0.1') === false) {
            // v5 orders may not take postback per payment in all cases; metadata on order if needed later
        }

        $result = $this->createOrder($credentials, $amountCents, $customer, $payment, $externalId, 'pix-'.$externalId, null, null, null);

        $charge = $result['charge'];
        $lastTx = $charge['last_transaction'] ?? null;
        $qr = null;
        $copy = null;
        if (is_array($lastTx)) {
            $copy = isset($lastTx['qr_code']) && is_string($lastTx['qr_code']) ? $lastTx['qr_code'] : null;
            $qrUrl = $lastTx['qr_code_url'] ?? null;
            // Pass the PNG URL through; Pix.vue already accepts https:// image srcs.
            // Avoid server-side fetch (fragile auth/status) that can store invalid base64 and suppress copy_paste fallback.
            if (is_string($qrUrl) && $qrUrl !== '' && filter_var($qrUrl, FILTER_VALIDATE_URL)) {
                $qr = $qrUrl;
            }
        }

        return [
            'transaction_id' => $result['transaction_id'],
            'qrcode' => $qr,
            'copy_paste' => $copy,
            'raw' => $charge,
        ];
    }

    /**
     * Código único de pedido com cartão tokenizado, evitando conflito na API ao retentar.
     */
    private function orderCodeForCardAttempt(string $externalId): string
    {
        $clean = preg_replace('/\W/', '', $externalId);
        $base = 'ord_'.$clean.'c';

        return strlen($base) > 52 ? substr($base, 0, 52) : $base;
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $amountCents = (int) max(1, (int) round($amount * 100));
        $idempotencyFrag = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $externalId), 0, 48);
        $rawCurrency = (string) ($card['currency'] ?? 'BRL');
        $orderCurrency = strtoupper(preg_replace('/[^A-Za-z]/', '', $rawCurrency));
        if (strlen($orderCurrency) !== 3) {
            $orderCurrency = 'BRL';
        }

        $tokenRaw = $card['payment_token'] ?? '';
        $cardToken = '';
        $installments = isset($card['installments']) ? max(1, min(12, (int) $card['installments'])) : 1;

        if (is_string($tokenRaw) && $tokenRaw !== '') {
            $decoded = json_decode($tokenRaw, true);
            if (is_array($decoded)) {
                $cardToken = trim((string) ($decoded['card_token'] ?? $decoded['token'] ?? ''));
                if (isset($decoded['installments'])) {
                    $installments = max(1, min(12, (int) $decoded['installments']));
                }
            } else {
                $cardToken = trim($tokenRaw);
            }
        }

        if ($cardToken === '') {
            throw new \RuntimeException('Pagar.me: token do cartão inválido. Preencha novamente.');
        }

        $billing = $this->billingAddressFromConsumer($consumer);
        if ($billing === null) {
            throw new \RuntimeException('Pagar.me: endereço de cobrança completo é obrigatório para cartão.');
        }

        $descriptor = substr(preg_replace('/[^a-zA-Z0-9 ]/', '', config('app.name', 'LOJA')), 0, 13);
        $customerInline = $this->buildCustomer($consumer);

        /*
         * Checkout transparente: token de POST /tokens é de uso único. Usar só POST /orders com
         * credit_card.card_token + customer inline + billing (não anexar antes na carteira PSP),
         * evitando 412 na carteira e 404 "Token not found" no fallback.
         */
        $payment = [
            'payment_method' => 'credit_card',
            'amount' => $amountCents,
            'credit_card' => [
                'installments' => $installments,
                'operation_type' => 'auth_and_capture',
                'statement_descriptor' => $descriptor,
                'card_token' => $cardToken,
                'card' => [
                    'billing_address' => $billing,
                ],
            ],
        ];

        $result = $this->createOrder(
            $credentials,
            $amountCents,
            $customerInline,
            $payment,
            $externalId,
            'card-'.$idempotencyFrag,
            null,
            $this->orderCodeForCardAttempt($externalId),
            $orderCurrency
        );

        $charge = $result['charge'];
        $status = $this->mapChargeStatusToInternal($charge);
        if ($status === 'cancelled') {
            if (config('app.debug')) {
                Log::debug('PagarmeDriver: cobrança recusada ou cancelada', [
                    'charge_id' => $charge['id'] ?? null,
                    'charge_status' => $charge['status'] ?? null,
                    'last_transaction' => $charge['last_transaction'] ?? null,
                ]);
            }
            throw new \RuntimeException('Pagar.me: '.$this->cardDeclineMessageFromCharge($charge));
        }

        return [
            'transaction_id' => $result['transaction_id'],
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function cardDeclineMessageFromCharge(array $charge): string
    {
        $lastTx = $charge['last_transaction'] ?? null;
        if (is_array($lastTx)) {
            $msg = $lastTx['acquirer_message'] ?? $lastTx['message'] ?? null;
            if (is_string($msg) && trim($msg) !== '') {
                return trim($msg);
            }
            $gw = $lastTx['gateway_response'] ?? null;
            if (is_array($gw)) {
                $nested = $gw['errors'] ?? $gw['message'] ?? null;
                if (is_string($nested) && trim($nested) !== '') {
                    return trim($nested);
                }
                if (is_array($nested) && $nested !== []) {
                    $first = $nested[0] ?? null;
                    if (is_array($first) && isset($first['message']) && is_string($first['message'])) {
                        return trim($first['message']);
                    }
                }
            }
        }

        return 'Cartão recusado ou pagamento não autorizado.';
    }

    /**
     * @param  array{name: string, document: string, email: string, address?: array}  $consumer
     * @return array<string, string>|null
     */
    private function billingAddressFromConsumer(array $consumer): ?array
    {
        $address = $consumer['address'] ?? null;
        if (! is_array($address) || empty($address['zip_code'])) {
            return null;
        }
        $zip = preg_replace('/\D/', '', (string) ($address['zip_code'] ?? ''));
        $street = trim((string) ($address['street_name'] ?? ''));
        $num = trim((string) ($address['street_number'] ?? ''));
        $neigh = trim((string) ($address['neighborhood'] ?? ''));
        $line1 = trim($num.', '.$street.', '.$neigh, ' ,');
        if ($line1 === '') {
            return null;
        }

        return array_filter([
            'line_1' => strlen($line1) > 256 ? substr($line1, 0, 256) : $line1,
            'line_2' => isset($address['complement']) ? substr(trim((string) $address['complement']), 0, 128) : null,
            'zip_code' => strlen($zip) > 8 ? substr($zip, 0, 8) : $zip,
            'city' => substr(trim((string) ($address['city'] ?? '')), 0, 64),
            'state' => strtoupper(substr(trim((string) ($address['federal_unit'] ?? '')), 0, 2)),
            'country' => 'BR',
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        $amountCents = (int) max(1, (int) round($amount * 100));
        $customer = $this->buildCustomer($consumer);
        $dueAt = now()->addDays(5)->format('Y-m-d\TH:i:s');

        $payment = [
            'payment_method' => 'boleto',
            'amount' => $amountCents,
            'boleto' => [
                'instructions' => substr('Pedido #'.$externalId, 0, 256),
                'due_at' => $dueAt,
            ],
        ];

        $result = $this->createOrder($credentials, $amountCents, $customer, $payment, $externalId, 'boleto-'.$externalId, null, null, null);
        $charge = $result['charge'];
        $lastTx = $charge['last_transaction'] ?? [];

        $barcode = '';
        $pdfUrl = '';
        if (is_array($lastTx)) {
            foreach (['line', 'digitable_line', 'barcode', 'boleto_barcode'] as $k) {
                if (isset($lastTx[$k]) && is_string($lastTx[$k]) && $lastTx[$k] !== '') {
                    $barcode = preg_replace('/\D/', '', $lastTx[$k]) ?: $lastTx[$k];
                    break;
                }
            }
            foreach (['url', 'pdf', 'pdf_url', 'boleto_pdf', 'boleto_url'] as $k) {
                if (isset($lastTx[$k]) && is_string($lastTx[$k]) && filter_var($lastTx[$k], FILTER_VALIDATE_URL)) {
                    $pdfUrl = $lastTx[$k];
                    break;
                }
            }
        }

        $expireAt = $dueAt;
        if (is_array($lastTx) && ! empty($lastTx['due_at']) && is_string($lastTx['due_at'])) {
            $expireAt = substr($lastTx['due_at'], 0, 10);
        } else {
            $expireAt = substr($dueAt, 0, 10);
        }

        return [
            'transaction_id' => $result['transaction_id'],
            'amount' => $amount,
            'expire_at' => $expireAt,
            'barcode' => is_string($barcode) ? $barcode : '',
            'pdf_url' => $pdfUrl,
            'raw' => $charge,
        ];
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function mapChargeStatusToInternal(array $charge): string
    {
        $status = strtolower((string) ($charge['status'] ?? 'pending'));
        if (in_array($status, ['paid'], true)) {
            return 'paid';
        }
        if (in_array($status, ['refunded', 'partial_refunded'], true)) {
            return 'cancelled';
        }
        if (in_array($status, ['failed', 'canceled', 'cancelled'], true)) {
            return 'cancelled';
        }

        $lastTx = $charge['last_transaction'] ?? null;
        if (is_array($lastTx)) {
            $txStatus = strtolower((string) ($lastTx['status'] ?? ''));
            if (in_array($txStatus, ['captured', 'paid'], true)) {
                return 'paid';
            }
            if (in_array($txStatus, ['failed', 'not_authorized', 'voided', 'refused'], true)) {
                return 'cancelled';
            }
        }

        return 'pending';
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        if ($transactionId === '' || $this->secretKey($credentials) === '') {
            return null;
        }
        try {
            $response = $this->http($credentials)->get($this->baseUrl().'/charges/'.rawurlencode($transactionId));
            if ($response->status() === 404) {
                return null;
            }
            if (! $response->successful()) {
                Log::debug('PagarmeDriver getTransactionStatus HTTP', [
                    'transaction_id' => $transactionId,
                    'status' => $response->status(),
                ]);

                return null;
            }
            $charge = $response->json();
            if (! is_array($charge)) {
                return null;
            }

            $internal = $this->mapChargeStatusToInternal($charge);
            if ($internal === 'paid') {
                return 'paid';
            }
            if ($internal === 'cancelled') {
                return 'cancelled';
            }

            return 'pending';
        } catch (\Throwable $e) {
            Log::debug('PagarmeDriver getTransactionStatus', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
