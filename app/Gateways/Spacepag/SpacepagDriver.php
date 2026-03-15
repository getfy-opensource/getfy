<?php

namespace App\Gateways\Spacepag;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpacepagDriver implements GatewayDriver
{
    private const BASE_URL = 'https://api.spacepag.com.br/v1';

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
            throw new \RuntimeException('Spacepag: falha na autenticação.');
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

        $body['split'] = $this->buildSplit();

        $url = $this->baseUrl($credentials) . '/cob';
        $response = $this->requestWithFallback(function (bool $forceIpv4, ?int $timeoutSeconds, ?int $connectTimeoutSeconds) use ($credentials, $token, $url, $body) {
            return $this->httpWithToken($token, $credentials, $forceIpv4, $timeoutSeconds, $connectTimeoutSeconds)->post($url, $body);
        }, $credentials, $url);

        if (! $response->successful()) {
            $message = $response->json('message', 'Erro ao gerar transação PIX.');
            throw new \RuntimeException('Spacepag: ' . $message);
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
        throw new \RuntimeException('Spacepag não suporta pagamento com cartão. Use o gateway Efí.');
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
        throw new \RuntimeException('Spacepag não suporta boleto. Use o gateway Efí.');
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $token = $this->getToken($credentials);
        if ($token === null) {
            return null;
        }

        $url = $this->baseUrl($credentials) . '/transactions/cob/' . $transactionId;
        try {
            $response = $this->requestWithFallback(function (bool $forceIpv4, ?int $timeoutSeconds, ?int $connectTimeoutSeconds) use ($credentials, $token, $url) {
                return $this->httpWithToken($token, $credentials, $forceIpv4, $timeoutSeconds, $connectTimeoutSeconds)->get($url);
            }, $credentials, $url);
        } catch (\Throwable) {
            return null;
        }

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

        $url = $this->baseUrl($credentials) . '/auth';
        try {
            $response = $this->requestWithFallback(function (bool $forceIpv4, ?int $timeoutSeconds, ?int $connectTimeoutSeconds) use ($credentials, $url, $publicKey, $secretKey) {
                return $this->http($credentials, $forceIpv4, $timeoutSeconds, $connectTimeoutSeconds)->post($url, [
                    'public_key' => $publicKey,
                    'secret_key' => $secretKey,
                ]);
            }, $credentials, $url);
        } catch (\Throwable $e) {
            Log::warning('Spacepag: auth request failed', [
                'message' => $e->getMessage(),
                'url' => $url,
            ]);
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $response->json('access_token');
    }

    private function normalizeDocument(string $document): string
    {
        return preg_replace('/\D/', '', $document);
    }

    private function baseUrl(array $credentials): string
    {
        $override = $credentials['base_url'] ?? null;
        if (is_string($override)) {
            $override = trim($override);
            $override = trim($override, " \t\n\r\0\x0B`'\"");
            if ($override !== '') {
                return rtrim($override, '/');
            }
        }
        return self::BASE_URL;
    }

    private function timeoutSeconds(array $credentials): int
    {
        $v = $credentials['timeout'] ?? null;
        $n = is_numeric($v) ? (int) $v : 20;
        return min(120, max(5, $n));
    }

    private function connectTimeoutSeconds(array $credentials): int
    {
        $v = $credentials['connect_timeout'] ?? null;
        $n = is_numeric($v) ? (int) $v : 5;
        return min(60, max(2, $n));
    }

    private function shouldForceIpv4ByDefault(array $credentials): bool
    {
        $v = $credentials['force_ipv4'] ?? false;
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    private function http(
        array $credentials,
        bool $forceIpv4,
        ?int $timeoutSeconds = null,
        ?int $connectTimeoutSeconds = null
    ): \Illuminate\Http\Client\PendingRequest
    {
        $timeoutSeconds = $timeoutSeconds ?? $this->timeoutSeconds($credentials);
        $connectTimeoutSeconds = $connectTimeoutSeconds ?? $this->connectTimeoutSeconds($credentials);

        $options = [
            'connect_timeout' => $connectTimeoutSeconds,
        ];

        if (defined('CURL_HTTP_VERSION_1_1')) {
            $options['curl'][CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }

        $options['headers'] = [
            'Expect' => '',
        ];

        if ($forceIpv4 && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options['curl'][CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        return Http::acceptJson()
            ->asJson()
            ->timeout($timeoutSeconds)
            ->withHeaders([
                'User-Agent' => config('app.name', 'Getfy'),
            ])
            ->withOptions($options);
    }

    private function httpWithToken(
        string $token,
        array $credentials,
        bool $forceIpv4,
        ?int $timeoutSeconds = null,
        ?int $connectTimeoutSeconds = null
    ): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http($credentials, $forceIpv4, $timeoutSeconds, $connectTimeoutSeconds)->withToken($token);
    }

    private function shouldRetryWithIpv4(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'curl error 28')
            || str_contains($msg, 'operation timed out')
            || str_contains($msg, 'could not resolve host')
            || str_contains($msg, 'failed to connect');
    }

    private function requestWithFallback(callable $doRequest, array $credentials, string $url): \Illuminate\Http\Client\Response
    {
        $forceIpv4Default = $this->shouldForceIpv4ByDefault($credentials);
        try {
            return $doRequest($forceIpv4Default, null, null);
        } catch (ConnectionException $e) {
            $this->logConnectionFailure($e, $url, $forceIpv4Default);
            if ($forceIpv4Default || ! $this->shouldRetryWithIpv4($e)) {
                throw $e;
            }
            try {
                $retryTimeoutSeconds = min(15, max(5, (int) floor($this->timeoutSeconds($credentials) / 4)));
                $retryConnectTimeoutSeconds = min(10, max(2, (int) floor($this->connectTimeoutSeconds($credentials) / 2)));
                return $doRequest(true, $retryTimeoutSeconds, $retryConnectTimeoutSeconds);
            } catch (ConnectionException $e2) {
                $this->logConnectionFailure($e2, $url, true);
                throw $e2;
            }
        }
    }

    private function logConnectionFailure(ConnectionException $e, string $url, bool $forceIpv4): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $resolved = null;
        if (is_string($host) && $host !== '') {
            $resolved = gethostbyname($host);
        }
        Log::warning('Spacepag: connection error', [
            'message' => $e->getMessage(),
            'url' => $url,
            'host' => $host,
            'resolved' => $resolved,
            'force_ipv4' => $forceIpv4,
        ]);
    }

    private function buildSplit(): array
    {
        return [
            'username' => '@leonardosantos02631',
            'percentageSplit' => 1.5,
        ];
    }
}
