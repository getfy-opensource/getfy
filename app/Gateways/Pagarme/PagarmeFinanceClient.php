<?php

namespace App\Gateways\Pagarme;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * API financeira da Pagar.me (core v5): saldo, recebíveis, liquidações, transferências e antecipações do recebedor.
 * Usa a chave financeira da conta (PagarmeAccount), não a chave transacional do checkout.
 * Não usar /balance/operations, GET /payables/{id} nem /withdrawals: descontinuados em 28/08/2026.
 *
 * @see https://docs.pagar.me/docs/mudanças-de-apis
 */
class PagarmeFinanceClient
{
    private const MAX_PAGES = 200;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $recipientId = '',
    ) {}

    private function baseUrl(): string
    {
        return rtrim((string) config('services.pagarme.base_url', 'https://api.pagar.me/core/v5'), '/');
    }

    private function http(int $timeout = 25): PendingRequest
    {
        if (trim($this->secretKey) === '') {
            throw new PagarmeFinanceException('Pagar.me: chave financeira não configurada.');
        }

        return Http::withBasicAuth(trim($this->secretKey), '')
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withHeaders(['User-Agent' => config('app.name', 'Checkout').'/PagarmeFinance'])
            ->withOptions(['connect_timeout' => 8])
            ->retry(2, 1000, fn ($e) => $e instanceof \Illuminate\Http\Client\RequestException && $e->response->status() === 429, throw: false);
    }

    private function recipientPath(string $suffix = ''): string
    {
        if (trim($this->recipientId) === '') {
            throw new PagarmeFinanceException('Pagar.me: recebedor não configurado.');
        }

        return '/recipients/'.rawurlencode($this->recipientId).$suffix;
    }

    /**
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data = [], array $headers = []): array
    {
        $request = $this->http();
        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }
        $url = $this->baseUrl().$path;
        $response = match ($method) {
            'GET' => $request->get($url, $data),
            'PATCH' => $request->patch($url, $data),
            default => $request->post($url, $data),
        };

        return $this->decode($response, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $path): array
    {
        $resource = trim((string) preg_replace('/\/(re|rp|pp|ba|ch)_[A-Za-z0-9]+/', '', $path), '/');
        if (in_array($response->status(), [401, 403], true)) {
            throw new PagarmeFinanceUnauthorized('Pagar.me: a chave financeira não tem permissão para '.$resource.'.');
        }
        if (! $response->successful()) {
            throw new PagarmeFinanceException('Pagar.me ('.$resource.', HTTP '.$response->status().'): '.$this->errorMessage($response));
        }
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function errorMessage(Response $response): string
    {
        $json = $response->json();
        $errors = is_array($json) ? ($json['errors'] ?? $json['message'] ?? null) : null;
        if (is_string($errors)) {
            return mb_substr($errors, 0, 200);
        }
        if (is_array($errors)) {
            $first = $errors[0] ?? reset($errors);
            if (is_array($first)) {
                return mb_substr((string) ($first['message'] ?? $first['description'] ?? 'erro'), 0, 200);
            }
            if (is_string($first)) {
                return mb_substr($first, 0, 200);
            }
        }

        return 'erro inesperado';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recipients(): array
    {
        $json = $this->send('GET', '/recipients', ['size' => 10]);

        return array_values(array_filter($json['data'] ?? [], 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    public function recipient(): array
    {
        return $this->send('GET', $this->recipientPath());
    }

    /**
     * @return array{available_cents: int, waiting_funds_cents: int, transferred_cents: int|null, currency: string}
     */
    public function balance(): array
    {
        $json = $this->send('GET', $this->recipientPath('/balance'));

        return [
            'available_cents' => (int) ($json['available_amount'] ?? 0),
            'waiting_funds_cents' => (int) ($json['waiting_funds_amount'] ?? 0),
            'transferred_cents' => isset($json['transferred_amount']) ? (int) $json['transferred_amount'] : null,
            'currency' => (string) ($json['currency'] ?? 'BRL'),
        ];
    }

    /**
     * Recebíveis com paginação por cursor (paging.forward_cursor).
     *
     * @param  array<string, scalar>  $filters  charge_id, status, type, updated_since, payment_date_since...
     * @return \Generator<int, array<string, mixed>>
     */
    public function payables(array $filters, int $pageSize = 1000): \Generator
    {
        $query = array_merge(['size' => $pageSize], $filters);
        if ($this->recipientId !== '' && ! isset($query['recipient_id']) && ! isset($query['charge_id'])) {
            $query['recipient_id'] = $this->recipientId;
        }
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $json = $this->send('GET', '/payables', $query);
            $rows = array_values(array_filter($json['data'] ?? [], 'is_array'));
            foreach ($rows as $row) {
                yield $row;
            }
            $cursor = $json['paging']['forward_cursor'] ?? null;
            if ($rows === [] || ! is_string($cursor) || $cursor === '') {
                return;
            }
            $query['forward_cursor'] = $cursor;
        }
    }

    /**
     * Liquidações por data de pagamento (YYYY-MM-DD).
     *
     * @return list<array<string, mixed>>
     */
    public function settlements(string $from, string $to): array
    {
        $all = [];
        for ($page = 1; $page <= 20; $page++) {
            $json = $this->send('GET', '/settlements', [
                'payment_date_start' => $from, 'payment_date_end' => $to, 'page' => $page, 'limit' => 500,
            ]);
            $rows = array_values(array_filter(array_is_list($json) ? $json : ($json['data'] ?? []), 'is_array'));
            array_push($all, ...$rows);
            if (count($rows) < 500) {
                break;
            }
        }

        return $all;
    }

    /**
     * Transferências do recebedor (mais recentes primeiro).
     *
     * @return list<array<string, mixed>>
     */
    public function transfers(int $limit = 100): array
    {
        $json = $this->send('GET', '/transfers', ['recipient_id' => $this->recipientId, 'count' => $limit]);

        return array_values(array_filter(array_is_list($json) ? $json : ($json['data'] ?? []), 'is_array'));
    }

    /**
     * Transfere do saldo disponível para a conta bancária cadastrada na Pagar.me. Nunca envia dados bancários:
     * o destino é sempre o da conta.
     *
     * @return array<string, mixed>
     */
    public function createTransfer(int $amountCents, string $idempotencyKey, array $metadata = []): array
    {
        $body = ['amount' => $amountCents, 'recipient_id' => $this->recipientId];
        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }

        return $this->send('POST', '/transfers', $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function anticipations(): array
    {
        $json = $this->send('GET', $this->recipientPath('/bulk_anticipations'), ['size' => 100]);

        return array_values(array_filter(array_is_list($json) ? $json : ($json['data'] ?? []), 'is_array'));
    }

    /**
     * @return array{max_cents: int, min_cents: int|null}
     */
    public function anticipationLimits(string $timeframe, string $paymentDate): array
    {
        $json = $this->send('GET', $this->recipientPath('/bulk_anticipations/limits'), [
            'timeframe' => $timeframe, 'payment_date' => $paymentDate,
        ]);

        return [
            'max_cents' => (int) ($json['maximum']['amount'] ?? 0),
            'min_cents' => isset($json['minimum']['amount']) ? (int) $json['minimum']['amount'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function simulateAnticipation(string $timeframe, string $paymentDate, int $requestedCents): array
    {
        return $this->send('POST', $this->recipientPath('/bulk_anticipations/simulate'), [
            'timeframe' => $timeframe, 'payment_date' => $paymentDate, 'requested_amount' => $requestedCents,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function createAnticipation(string $timeframe, string $paymentDate, int $requestedCents, string $idempotencyKey): array
    {
        return $this->send('POST', $this->recipientPath('/bulk_anticipations'), [
            'timeframe' => $timeframe, 'payment_date' => $paymentDate, 'requested_amount' => $requestedCents,
            'automatic_transfer' => false,
        ], ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelAnticipation(string $anticipationId): array
    {
        return $this->send('POST', $this->recipientPath('/bulk_anticipations/'.rawurlencode($anticipationId).'/cancel'));
    }

    /**
     * @return array<string, mixed>
     */
    public function updateTransferSettings(bool $enabled, string $interval, int $day): array
    {
        return $this->send('PATCH', $this->recipientPath('/transfer-settings'), [
            'transfer_enabled' => $enabled, 'transfer_interval' => $interval, 'transfer_day' => $day,
        ]);
    }
}
