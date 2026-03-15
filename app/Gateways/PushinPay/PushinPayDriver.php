<?php

namespace App\Gateways\PushinPay;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushinPayDriver implements GatewayDriver
{
    private const BASE_URL_PRODUCTION = 'https://api.pushinpay.com.br/api';

    private const BASE_URL_SANDBOX = 'https://api-sandbox.pushinpay.com.br/api';

    private function getBaseUrl(array $credentials): string
    {
        $sandbox = isset($credentials['sandbox']) && filter_var($credentials['sandbox'], FILTER_VALIDATE_BOOLEAN);

        return $sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    private function getToken(array $credentials): ?string
    {
        $token = trim($credentials['api_token'] ?? '');
        if ($token === '') {
            return null;
        }
        return $token;
    }

    private function http(string $token, int $timeoutSeconds = 20): \Illuminate\Http\Client\PendingRequest
    {
        $timeoutSeconds = min(120, max(5, $timeoutSeconds));
        $connectTimeoutSeconds = min(60, max(2, (int) ceil($timeoutSeconds / 4)));

        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout($timeoutSeconds)
            ->withOptions(['connect_timeout' => $connectTimeoutSeconds]);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function testConnection(array $credentials): bool
    {
        $token = $this->getToken($credentials);
        if ($token === null) {
            return false;
        }
        $baseUrl = $this->getBaseUrl($credentials);
        try {
            $response = $this->http($token)->get($baseUrl . '/transactions', ['per_page' => 1]);
            if ($response->successful()) {
                return true;
            }
            if ($response->status() === 401) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::debug('PushinPayDriver testConnection error', ['message' => $e->getMessage()]);
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
        $token = $this->getToken($credentials);
        if ($token === null) {
            throw new \RuntimeException('Pushin Pay: API Token não configurado.');
        }
        $valueCentavos = (int) round($amount * 100);
        if ($valueCentavos < 50) {
            $valueCentavos = 50;
        }
        $baseUrl = $this->getBaseUrl($credentials);
        $body = [
            'value' => $valueCentavos,
            'webhook_url' => $postbackUrl,
        ];
        $response = $this->http($token)->post($baseUrl . '/pix/cashIn', $body);

        if (! $response->successful()) {
            $message = $response->json('message', 'Não foi possível gerar o PIX.');
            if (is_array($message)) {
                $message = json_encode($message);
            }
            $errors = $response->json('errors', []);
            if (is_array($errors) && $errors !== []) {
                $first = reset($errors);
                $message = is_array($first) ? (reset($first) ?? $message) : (string) $first;
            }
            Log::warning('PushinPayDriver createPixPayment failed', [
                'status' => $response->status(),
                'message' => $message,
            ]);
            throw new \RuntimeException('Pushin Pay: ' . trim((string) $message));
        }

        $data = $response->json();
        $id = $data['id'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('Pushin Pay: resposta sem identificador da transação.');
        }

        return [
            'transaction_id' => (string) $id,
            'qrcode' => $data['qr_code_base64'] ?? null,
            'copy_paste' => $data['qr_code'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $token = $this->getToken($credentials);
        if ($token === null) {
            return null;
        }
        $baseUrl = $this->getBaseUrl($credentials);
        try {
            $response = $this->http($token)->get($baseUrl . '/transactions/' . $transactionId);

            if (! $response->successful()) {
                if ($response->status() === 404) {
                    return null;
                }
                Log::debug('PushinPayDriver getTransactionStatus error', [
                    'transaction_id' => $transactionId,
                    'status' => $response->status(),
                ]);
                return null;
            }
            $data = $response->json();
            if (is_array($data) && $data === []) {
                return null;
            }
            $status = $data['status'] ?? null;
            if (! is_string($status)) {
                return null;
            }
            $status = strtolower($status);
            if ($status === 'paid') {
                return 'paid';
            }
            if ($status === 'canceled' || $status === 'cancelled') {
                return 'cancelled';
            }
            return 'pending';
        } catch (\Throwable $e) {
            Log::debug('PushinPayDriver getTransactionStatus error', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new \RuntimeException('Pushin Pay não suporta pagamento com cartão.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('Pushin Pay não suporta boleto.');
    }
}
