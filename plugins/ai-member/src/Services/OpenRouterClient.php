<?php

namespace Plugins\AiMember\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Plugins\AiMember\Models\AiMemberConnection;
use RuntimeException;

class OpenRouterClient
{
    private const BASE_URL = 'https://openrouter.ai/api/v1';

    public function __construct(
        private readonly ?int $tenantId = null,
    ) {}

    public function forTenant(?int $tenantId): self
    {
        return new self($tenantId);
    }

    public function isConfigured(): bool
    {
        return $this->connection()?->isConfigured() ?? false;
    }

    public function chatCompletions(array $payload): array
    {
        $response = $this->request('post', '/chat/completions', $payload);

        return $response->json();
    }

    public function embeddings(array $payload): array
    {
        $response = $this->request('post', '/embeddings', $payload);

        return $response->json();
    }

    public function transcribe(string $base64Audio, string $mimeType = 'audio/webm'): string
    {
        $conn = $this->connection();
        if (! $conn?->isConfigured() || ! $conn->is_active) {
            throw new RuntimeException('OpenRouter não configurado ou inativo.');
        }

        $binary = base64_decode($base64Audio, true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Áudio inválido.');
        }

        $extension = match ($mimeType) {
            'audio/mp4', 'audio/m4a' => 'm4a',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            default => 'webm',
        };

        $response = Http::baseUrl(self::BASE_URL)
            ->withToken($conn->api_key)
            ->withHeaders([
                'HTTP-Referer' => config('app.url', 'https://getfy.org'),
                'X-Title' => 'Getfy AI Member',
            ])
            ->timeout(120)
            ->attach('file', $binary, "audio.{$extension}")
            ->post('/audio/transcriptions', [
                'model' => 'openai/whisper-1',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?? 'Falha na transcrição.');
        }

        return trim((string) ($response->json('text') ?? ''));
    }

    public function listModels(): array
    {
        $response = $this->request('get', '/models');

        return $response->json('data') ?? [];
    }

    public function testConnection(): bool
    {
        $conn = $this->connection();
        if (! $conn?->isConfigured()) {
            throw new RuntimeException('API key não configurada.');
        }

        $this->listModels();
        $conn->last_tested_at = now();
        $conn->last_error = null;
        $conn->save();

        return true;
    }

    private function request(string $method, string $path, array $payload = []): Response
    {
        $conn = $this->connection();
        if (! $conn?->isConfigured() || ! $conn->is_active) {
            throw new RuntimeException('OpenRouter não configurado ou inativo.');
        }

        $http = Http::baseUrl(self::BASE_URL)
            ->withToken($conn->api_key)
            ->withHeaders([
                'HTTP-Referer' => config('app.url', 'https://getfy.org'),
                'X-Title' => 'Getfy AI Member',
            ])
            ->timeout(90)
            ->acceptJson();

        $response = match ($method) {
            'get' => $http->get($path, $payload),
            default => $http->post($path, $payload),
        };

        if (! $response->successful()) {
            $message = $response->json('error.message')
                ?? $response->json('message')
                ?? $response->body();
            if ($conn) {
                $conn->last_error = is_string($message) ? mb_substr($message, 0, 500) : 'Erro na API OpenRouter';
                $conn->save();
            }
            throw new RuntimeException(is_string($message) ? $message : 'Falha na API OpenRouter.');
        }

        return $response;
    }

    private function connection(): ?AiMemberConnection
    {
        if ($this->tenantId === null) {
            return null;
        }

        return AiMemberConnection::forTenant($this->tenantId)->first();
    }
}
