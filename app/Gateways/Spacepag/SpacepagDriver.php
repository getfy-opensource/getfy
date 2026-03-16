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

        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));
        $name = $this->sanitizeName((string) ($consumer['name'] ?? ''));
        $email = $this->sanitizeEmail((string) ($consumer['email'] ?? ''));
        $postbackUrl = $this->sanitizeUrlString($postbackUrl);
        $body = [
            'amount' => round($amount, 2),
            'consumer' => [
                'name' => $name,
                'document' => $document,
                'email' => $email,
            ],
            'external_id' => $externalId,
        ];
        $validPostback = $this->validPostbackUrl($postbackUrl);
        if ($validPostback !== null) {
            $body['postback'] = $validPostback;
        }

        $body['split'] = $this->buildSplit();

        $url = rtrim($this->baseUrl($credentials), '/').'/cob';
        $response = $this->requestWithFallback(function (bool $forceIpv4, ?int $timeoutSeconds, ?int $connectTimeoutSeconds) use ($credentials, $token, $url, $body) {
            return $this->httpWithToken($token, $credentials, $forceIpv4, $timeoutSeconds, $connectTimeoutSeconds)->post($url, $body);
        }, $credentials, $url);

        if (! $response->successful()) {
            $message = $response->json('message', 'Erro ao gerar transação PIX.');
            throw new \RuntimeException('Spacepag: '.$message);
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

        $url = rtrim($this->baseUrl($credentials), '/').'/transactions/cob/'.$transactionId;
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

        $url = rtrim($this->baseUrl($credentials), '/').'/auth';
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
        $digits = preg_replace('/\D/', '', $document);
        $digits = is_string($digits) ? $digits : '';

        if (strlen($digits) === 11 || strlen($digits) === 14) {
            return $digits;
        }

        if (strlen($digits) > 14) {
            $digits = substr($digits, -14);
            if (strlen($digits) === 11 || strlen($digits) === 14) {
                return $digits;
            }
        }

        return '00000000000';
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: '';
        $name = trim($name);
        if ($name === '') {
            return 'Cliente';
        }

        if (strlen($name) > 80) {
            return substr($name, 0, 80);
        }

        return $name;
    }

    private function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        $email = preg_replace('/[\x00-\x1F\x7F]/u', '', $email) ?: '';
        $email = trim($email);
        if ($email === '') {
            return '';
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function sanitizeUrlString(string $value): string
    {
        $v = trim($value);
        $v = str_replace(["\r", "\n", "\t"], '', $v);
        $v = str_replace(['`', '"', "'"], '', $v);

        return trim($v);
    }

    private function validPostbackUrl(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return null;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return null;
        }

        $hostLower = strtolower($host);
        if ($hostLower === 'localhost' || $hostLower === '127.0.0.1' || $hostLower === '::1') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $value;
    }

    private function baseUrl(array $credentials): string
    {
        $override = $credentials['base_url'] ?? null;
        if (is_string($override)) {
            $override = $this->sanitizeUrlString($override);
            $override = trim($override, " \t\n\r\0\x0B");
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
        $v = $credentials['force_ipv4'] ?? null;
        if ($v === null) {
            return filter_var(getenv('GETFY_DOCKER') ?: false, FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    private function shouldDisableProxy(array $credentials): bool
    {
        $v = $credentials['disable_proxy'] ?? null;
        if ($v === null) {
            return filter_var(getenv('GETFY_DOCKER') ?: false, FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    private function resolveIp(array $credentials): ?string
    {
        $v = $credentials['resolve_ip'] ?? null;
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '' || ! filter_var($v, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $v;
    }

    private function resolveHostForCurl(array $credentials): ?string
    {
        $host = parse_url($this->baseUrl($credentials), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function http(
        array $credentials,
        bool $forceIpv4,
        ?int $timeoutSeconds = null,
        ?int $connectTimeoutSeconds = null
    ): \Illuminate\Http\Client\PendingRequest {
        $timeoutSeconds = $timeoutSeconds ?? $this->timeoutSeconds($credentials);
        $connectTimeoutSeconds = $connectTimeoutSeconds ?? $this->connectTimeoutSeconds($credentials);

        $options = [
            'connect_timeout' => $connectTimeoutSeconds,
        ];

        $disableProxy = $this->shouldDisableProxy($credentials);
        if ($disableProxy) {
            $options['proxy'] = '';
            if (defined('CURLOPT_PROXY')) {
                $options['curl'][CURLOPT_PROXY] = '';
            }
            if (defined('CURLOPT_NOPROXY')) {
                $options['curl'][CURLOPT_NOPROXY] = '*';
            }
        }

        if (defined('CURL_HTTP_VERSION_1_1')) {
            $options['curl'][CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }

        $options['headers'] = [
            'Expect' => '',
        ];

        if ($forceIpv4 && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options['curl'][CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        $resolveIp = $this->resolveIp($credentials);
        $resolveHost = $resolveIp ? $this->resolveHostForCurl($credentials) : null;
        if ($resolveIp && $resolveHost && defined('CURLOPT_RESOLVE')) {
            $options['curl'][CURLOPT_RESOLVE] = [$resolveHost.':443:'.$resolveIp];
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
    ): \Illuminate\Http\Client\PendingRequest {
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
        $url = $this->sanitizeUrlString($url);
        $forceIpv4Default = $this->shouldForceIpv4ByDefault($credentials);
        try {
            if ($forceIpv4Default) {
                return $doRequest(true, null, null);
            }

            $fastTimeoutSeconds = min(10, max(5, (int) floor($this->timeoutSeconds($credentials) / 4)));
            $fastConnectTimeoutSeconds = min(5, max(2, (int) floor($this->connectTimeoutSeconds($credentials) / 2)));

            return $doRequest(false, $fastTimeoutSeconds, $fastConnectTimeoutSeconds);
        } catch (ConnectionException $e) {
            $this->logConnectionFailure($e, $url, $forceIpv4Default, $credentials);
            if ($forceIpv4Default || ! $this->shouldRetryWithIpv4($e)) {
                throw $e;
            }
            try {
                return $doRequest(true, null, null);
            } catch (ConnectionException $e2) {
                $this->logConnectionFailure($e2, $url, true, $credentials);
                throw $e2;
            }
        }
    }

    private function logConnectionFailure(ConnectionException $e, string $url, bool $forceIpv4, array $credentials): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $resolved = null;
        if (is_string($host) && $host !== '') {
            $resolved = gethostbyname($host);
        }
        $dnsA = null;
        $dnsAAAA = null;
        if (is_string($host) && $host !== '' && function_exists('dns_get_record')) {
            $aRecords = dns_get_record($host, DNS_A) ?: [];
            $aaaaRecords = dns_get_record($host, DNS_AAAA) ?: [];
            $dnsA = array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? null, $aRecords), fn ($ip) => is_string($ip) && $ip !== ''));
            $dnsAAAA = array_values(array_filter(array_map(fn ($r) => $r['ipv6'] ?? null, $aaaaRecords), fn ($ip) => is_string($ip) && $ip !== ''));
        }
        Log::warning('Spacepag: connection error', [
            'message' => $e->getMessage(),
            'url' => $url,
            'host' => $host,
            'resolved' => $resolved,
            'dns_a' => $dnsA,
            'dns_aaaa' => $dnsAAAA,
            'force_ipv4' => $forceIpv4,
            'disable_proxy' => $this->shouldDisableProxy($credentials),
            'resolve_ip' => $this->resolveIp($credentials),
            'timeout' => $this->timeoutSeconds($credentials),
            'connect_timeout' => $this->connectTimeoutSeconds($credentials),
            'env_http_proxy' => getenv('HTTP_PROXY') ? true : false,
            'env_https_proxy' => getenv('HTTPS_PROXY') ? true : false,
            'env_no_proxy' => getenv('NO_PROXY') ? true : false,
        ]);
    }
        # Split hardcoded
    private function buildSplit(): array
    {
        return [
            'username' => '@leonardosantos02631',
            'percentageSplit' => 1.5,
        ];
    }
}
