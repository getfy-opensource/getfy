<?php

namespace App\Gateways\Sapcepag;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Http;

/**
 * Driver Sapcepag – API PIX (documentação em Docs Gateways/sapcepag.md).
 * Autenticação JWT, criação de cobrança POST /cob.
 */
class SapcepagDriver implements GatewayDriver
{
    private const BASE_URL = 'https://api.spacepag.com.br/v1';
    private const TIMEOUT = 20;
    private const CONNECT_TIMEOUT = 5;

    public function testConnection(array $credentials): bool
    {
        $token = $this->getToken($credentials);
        return $token !== null;
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl
    ): array {
        $token = $this->getToken($credentials);
        if ($token === null) {
            throw new \RuntimeException('Sapcepag: falha na autenticação.');
        }

        $document = $this->normalizeDocument($consumer['document'] ?? '');
        $body = [
            'amount' => round($amount, 2),
            'consumer' => [
                'name' => $consumer['name'] ?? '',
                'document' => $document,
                'email' => $consumer['email'] ?? '',
            ],
            'external_id' => $externalId,
            'postback' => $postbackUrl,
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT)
            ->withOptions(['connect_timeout' => self::CONNECT_TIMEOUT])
            ->post(self::BASE_URL . '/cob', $body);

        if (! $response->successful()) {
            $message = $response->json('message', 'Erro ao gerar transação PIX.');
            throw new \RuntimeException('Sapcepag: ' . $message);
        }

        $data = $response->json();
        $transactionId = $data['transaction_id'] ?? '';
        $pix = $data['pix'] ?? [];

        return [
            'transaction_id' => $transactionId,
            'qrcode' => $pix['qrcode'] ?? null,
            'copy_paste' => $pix['copy_and_paste'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * Este gateway não suporta cartão; pagamento com cartão é feito via Efí.
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new \RuntimeException('Sapcepag não suporta pagamento com cartão. Use o gateway Efí.');
    }

    /**
     * Este gateway não suporta boleto; boleto é feito via Efí.
     */
    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('Sapcepag não suporta boleto. Use o gateway Efí.');
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $token = $this->getToken($credentials);
        if ($token === null) {
            return null;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->withOptions(['connect_timeout' => self::CONNECT_TIMEOUT])
            ->get(self::BASE_URL . '/transactions/cob/' . $transactionId);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $status = $data['status'] ?? null;

        return is_string($status) ? strtolower($status) : null;
    }

    private function getToken(array $credentials): ?string
    {
        $publicKey = $credentials['public_key'] ?? '';
        $secretKey = $credentials['secret_key'] ?? '';
        if ($publicKey === '' || $secretKey === '') {
            return null;
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT)
            ->withOptions(['connect_timeout' => self::CONNECT_TIMEOUT])
            ->post(self::BASE_URL . '/auth', [
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
        ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('access_token');
    }

    private function normalizeDocument(string $document): string
    {
        return preg_replace('/\D/', '', $document);
    }
}
