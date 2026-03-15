<?php

namespace App\Gateways\Asaas;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AsaasDriver implements GatewayDriver
{
    private const BASE_URL_PRODUCTION = 'https://api.asaas.com/v3';

    private const BASE_URL_SANDBOX = 'https://api-sandbox.asaas.com/v3';

    private function getBaseUrl(array $credentials): string
    {
        $sandbox = isset($credentials['sandbox']) && filter_var($credentials['sandbox'], FILTER_VALIDATE_BOOLEAN);

        return $sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    private function getApiKey(array $credentials): ?string
    {
        $key = trim($credentials['api_key'] ?? '');
        if ($key === '') {
            return null;
        }
        return $key;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function http(array $credentials, int $timeout = 20): \Illuminate\Http\Client\PendingRequest
    {
        $apiKey = $this->getApiKey($credentials);
        if ($apiKey === null) {
            throw new \RuntimeException('Asaas: API Key não configurada.');
        }
        return Http::withHeaders([
            'access_token' => $apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => config('app.name', 'Checkout'),
        ])->acceptJson()->timeout($timeout)->withOptions(['connect_timeout' => min(60, max(2, (int) ceil($timeout / 4)))]);
    }

    /**
     * Normaliza telefone para o formato Asaas: apenas dígitos, 11 caracteres (DDD + 9 + 8 dígitos) para celular.
     * Remove código do país 55, converte fixo (10 dígitos) em celular (insere 9 após DDD) e retorna vazio se inválido.
     */
    private function normalizePhoneForAsaas(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) > 11 && substr($digits, 0, 2) === '55') {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');
        if (strlen($digits) > 11) {
            $digits = substr($digits, -11);
        }
        if (strlen($digits) < 10) {
            return '';
        }
        // Celular no Brasil: 11 dígitos = DDD (2) + 9 + 8 dígitos. Se veio com 10 (fixo), insere 9 após DDD.
        if (strlen($digits) === 10) {
            $digits = substr($digits, 0, 2) . '9' . substr($digits, 2);
        }
        return $digits;
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string, phone?: string, address?: array}  $consumer
     */
    private function ensureCustomer(array $credentials, array $consumer, string $externalId): string
    {
        $baseUrl = $this->getBaseUrl($credentials);
        $document = preg_replace('/\D/', '', $consumer['document'] ?? '');
        if (strlen($document) < 11) {
            $document = '00000000000';
        }
        $mobilePhone = $this->normalizePhoneForAsaas($consumer['phone'] ?? '');
        $body = [
            'name' => trim($consumer['name'] ?? '') ?: 'Cliente',
            'cpfCnpj' => $document,
            'email' => $consumer['email'] ?? '',
            'externalReference' => 'order_' . $externalId,
        ];
        if ($mobilePhone !== '') {
            $body['mobilePhone'] = $mobilePhone;
        }
        $address = $consumer['address'] ?? null;
        if (is_array($address)) {
            if (! empty($address['zip_code'])) {
                $body['postalCode'] = preg_replace('/\D/', '', $address['zip_code']);
                if (strlen($body['postalCode']) > 8) {
                    $body['postalCode'] = substr($body['postalCode'], 0, 8);
                }
            }
            if (! empty($address['street_name'])) {
                $body['address'] = $address['street_name'];
            }
            if (isset($address['street_number'])) {
                $body['addressNumber'] = (string) $address['street_number'];
            }
            if (! empty($address['neighborhood'])) {
                $body['province'] = $address['neighborhood'];
            }
            if (! empty($address['city'])) {
                $body['city'] = $address['city'];
            }
            if (! empty($address['federal_unit'])) {
                $body['state'] = strtoupper(substr((string) $address['federal_unit'], 0, 2));
            }
        }
        $response = $this->http($credentials)->post($baseUrl . '/customers', $body);
        if (! $response->successful()) {
            $err = $response->json('errors', []);
            $msg = is_array($err) && isset($err[0]['description']) ? $err[0]['description'] : $response->json('error', 'Não foi possível criar o cliente.');
            Log::warning('AsaasDriver ensureCustomer failed', ['status' => $response->status(), 'order_id' => $externalId]);
            throw new \RuntimeException('Asaas: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }
        $data = $response->json();
        $id = $data['id'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('Asaas: resposta sem ID do cliente.');
        }
        return (string) $id;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function testConnection(array $credentials): bool
    {
        $apiKey = $this->getApiKey($credentials);
        if ($apiKey === null) {
            return false;
        }
        $baseUrl = $this->getBaseUrl($credentials);
        try {
            $response = $this->http($credentials)->get($baseUrl . '/customers', ['limit' => 1]);
            if ($response->successful()) {
                return true;
            }
            if ($response->status() === 401) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::debug('AsaasDriver testConnection error', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{transaction_id: string, qrcode?: string, copy_paste?: string, raw?: array}
     */
    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl
    ): array {
        $baseUrl = $this->getBaseUrl($credentials);
        $customerId = $this->ensureCustomer($credentials, $consumer, $externalId);
        $dueDate = now()->addDays(3)->format('Y-m-d');
        $body = [
            'customer' => $customerId,
            'billingType' => 'PIX',
            'value' => round($amount, 2),
            'dueDate' => $dueDate,
            'externalReference' => $externalId,
        ];
        $response = $this->http($credentials)->post($baseUrl . '/lean/payments', $body);
        if (! $response->successful()) {
            $err = $response->json('errors', []);
            $msg = is_array($err) && isset($err[0]['description']) ? $err[0]['description'] : 'Não foi possível gerar o PIX.';
            Log::warning('AsaasDriver createPixPayment failed', ['status' => $response->status(), 'order_id' => $externalId]);
            throw new \RuntimeException('Asaas: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }
        $data = $response->json();
        $paymentId = $data['id'] ?? null;
        if (empty($paymentId)) {
            throw new \RuntimeException('Asaas: resposta sem identificador do pagamento PIX.');
        }
        $qrResponse = $this->http($credentials)->get($baseUrl . '/payments/' . $paymentId . '/pixQrCode');
        $qrcode = null;
        $copyPaste = null;
        if ($qrResponse->successful()) {
            $qrData = $qrResponse->json();
            $qrcode = $qrData['encodedImage'] ?? null;
            $copyPaste = $qrData['payload'] ?? null;
        }
        return [
            'transaction_id' => (string) $paymentId,
            'qrcode' => $qrcode,
            'copy_paste' => $copyPaste,
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{transaction_id: string, amount: float, expire_at: string, barcode: string, pdf_url: string, raw?: array}
     */
    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        $baseUrl = $this->getBaseUrl($credentials);
        $customerId = $this->ensureCustomer($credentials, $consumer, $externalId);
        $dueDate = now()->addDays(5)->format('Y-m-d');
        $body = [
            'customer' => $customerId,
            'billingType' => 'BOLETO',
            'value' => round($amount, 2),
            'dueDate' => $dueDate,
            'externalReference' => $externalId,
        ];
        $response = $this->http($credentials)->post($baseUrl . '/lean/payments', $body);
        if (! $response->successful()) {
            $err = $response->json('errors', []);
            $msg = is_array($err) && isset($err[0]['description']) ? $err[0]['description'] : 'Não foi possível gerar o boleto.';
            Log::warning('AsaasDriver createBoletoPayment failed', ['status' => $response->status(), 'order_id' => $externalId]);
            throw new \RuntimeException('Asaas: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }
        $data = $response->json();
        $paymentId = $data['id'] ?? null;
        if (empty($paymentId)) {
            throw new \RuntimeException('Asaas: resposta sem identificador do boleto.');
        }
        $pdfUrl = $data['bankSlipUrl'] ?? '';
        $dueDateStr = $data['dueDate'] ?? $dueDate;
        if (is_string($dueDateStr) && strlen($dueDateStr) >= 10) {
            $dueDateStr = substr($dueDateStr, 0, 10);
        }
        $barcode = '';
        $idFieldResponse = $this->http($credentials)->get($baseUrl . '/lean/payments/' . $paymentId . '/identificationField');
        if ($idFieldResponse->successful()) {
            $idFieldData = $idFieldResponse->json();
            $barcode = $idFieldData['identificationField'] ?? $idFieldData['barCode'] ?? '';
        }
        return [
            'transaction_id' => (string) $paymentId,
            'amount' => (float) ($data['value'] ?? $amount),
            'expire_at' => $dueDateStr,
            'barcode' => $barcode,
            'pdf_url' => $pdfUrl,
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string, address?: array}  $consumer
     * @param  array{payment_token?: string, card_mask?: string, installments?: int, card_holder_name?: string, card_number?: string, card_expiry_month?: string, card_expiry_year?: string, card_ccv?: string}  $card
     * @return array{transaction_id: string, status?: string}
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $baseUrl = $this->getBaseUrl($credentials);
        $customerId = $this->ensureCustomer($credentials, $consumer, $externalId);
        $address = $consumer['address'] ?? null;
        if (! is_array($address) || empty($address['zip_code']) || ! isset($address['street_number'])) {
            throw new \RuntimeException('Asaas: endereço completo é obrigatório para pagamento com cartão.');
        }
        $document = preg_replace('/\D/', '', $consumer['document'] ?? '');
        if (strlen($document) < 11) {
            $document = '00000000000';
        }
        $postalCode = preg_replace('/\D/', '', $address['zip_code'] ?? '');
        if (strlen($postalCode) > 8) {
            $postalCode = substr($postalCode, 0, 8);
        }
        $phoneNormalized = $this->normalizePhoneForAsaas($consumer['phone'] ?? '');
        $holderInfo = [
            'name' => trim($consumer['name'] ?? '') ?: 'Titular',
            'email' => $consumer['email'] ?? '',
            'cpfCnpj' => $document,
            'postalCode' => $postalCode,
            'addressNumber' => (string) ($address['street_number'] ?? ''),
            'addressComplement' => trim((string) ($address['complement'] ?? '')) ?: null,
            'phone' => $phoneNormalized !== '' ? $phoneNormalized : null,
            'mobilePhone' => $phoneNormalized !== '' ? $phoneNormalized : null,
        ];
        $expYear = (string) ($card['card_expiry_year'] ?? '');
        if (strlen($expYear) === 2 && is_numeric($expYear)) {
            $expYear = '20' . $expYear;
        }
        $creditCard = [
            'holderName' => trim((string) ($card['card_holder_name'] ?? '')),
            'number' => preg_replace('/\D/', '', (string) ($card['card_number'] ?? '')),
            'expiryMonth' => str_pad((string) ($card['card_expiry_month'] ?? ''), 2, '0', STR_PAD_LEFT),
            'expiryYear' => $expYear,
            'ccv' => (string) ($card['card_ccv'] ?? ''),
        ];
        if ($creditCard['number'] === '' || $creditCard['ccv'] === '') {
            throw new \RuntimeException('Asaas: dados do cartão incompletos.');
        }
        $installments = isset($card['installments']) ? max(1, min(12, (int) $card['installments'])) : 1;
        $body = [
            'customer' => $customerId,
            'billingType' => 'CREDIT_CARD',
            'dueDate' => now()->format('Y-m-d'),
            'externalReference' => $externalId,
            'creditCard' => $creditCard,
            'creditCardHolderInfo' => $holderInfo,
            'remoteIp' => request()->ip() ?? '127.0.0.1',
        ];
        if ($installments === 1) {
            $body['value'] = round($amount, 2);
        } else {
            $body['installmentCount'] = $installments;
            $body['installmentValue'] = round($amount / $installments, 2);
        }
        $response = $this->http($credentials, 65)->post($baseUrl . '/lean/payments', $body);
        if (! $response->successful()) {
            $err = $response->json('errors', []);
            $desc = is_array($err) && isset($err[0]['description']) ? $err[0]['description'] : '';
            Log::warning('AsaasDriver createCardPayment failed', [
                'status' => $response->status(),
                'order_id' => $externalId,
                'description' => $desc,
            ]);
            $userMsg = 'Asaas: não foi possível processar o cartão. Verifique os dados e tente novamente.';
            if (stripos($desc, 'autoriz') !== false || stripos($desc, 'recusad') !== false) {
                $userMsg = 'Cartão recusado. Verifique os dados ou tente outro cartão.';
            }
            throw new \RuntimeException($userMsg);
        }
        $data = $response->json();
        $paymentId = $data['id'] ?? null;
        if (empty($paymentId)) {
            throw new \RuntimeException('Asaas: resposta sem identificador do pagamento.');
        }
        $status = $data['status'] ?? null;
        $mapped = 'pending';
        if (is_string($status)) {
            $s = strtoupper($status);
            if (in_array($s, ['CONFIRMED', 'RECEIVED'], true)) {
                $mapped = 'paid';
            } elseif (in_array($s, ['CANCELLED', 'REFUNDED'], true)) {
                $mapped = 'cancelled';
            }
        }
        return [
            'transaction_id' => (string) $paymentId,
            'status' => $mapped,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $baseUrl = $this->getBaseUrl($credentials);
        try {
            $response = $this->http($credentials)->get($baseUrl . '/lean/payments/' . $transactionId);
            if (! $response->successful()) {
                if ($response->status() === 404) {
                    return null;
                }
                Log::debug('AsaasDriver getTransactionStatus error', [
                    'transaction_id' => $transactionId,
                    'status' => $response->status(),
                ]);
                return null;
            }
            $data = $response->json();
            $status = $data['status'] ?? null;
            if (! is_string($status)) {
                return null;
            }
            $s = strtoupper($status);
            if (in_array($s, ['CONFIRMED', 'RECEIVED'], true)) {
                return 'paid';
            }
            if (in_array($s, ['CANCELLED', 'REFUNDED', 'OVERDUE'], true)) {
                return 'cancelled';
            }
            return 'pending';
        } catch (\Throwable $e) {
            Log::debug('AsaasDriver getTransactionStatus error', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

}
