<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $webhookId,
        public string $eventClass,
        public array $payload
    ) {}

    public function handle(): void
    {
        $webhook = Webhook::find($this->webhookId);

        if (! $webhook || ! $webhook->is_active) {
            return;
        }

        $eventLabel = config('webhook_events.events')[$this->eventClass] ?? $this->eventClass;
        $eventSlug = config('webhook_events.event_slugs')[$this->eventClass] ?? $this->eventClass;
        $body = [
            'event' => $eventSlug,
            'event_label' => $eventLabel,
            'payload' => $this->payload,
            'timestamp' => now()->toIso8601String(),
        ];

        $request = Http::timeout(15)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($body), 'application/json');

        try {
            // `bearer_token` é cast como encrypted; se APP_KEY estiver incorreta/mudou, isso pode lançar exceção.
            $token = null;
            try {
                $token = $webhook->bearer_token;
            } catch (\Throwable $e) {
                WebhookLog::create([
                    'webhook_id' => $webhook->id,
                    'event' => $eventSlug,
                    'event_label' => $eventLabel,
                    'request_payload' => $body,
                    'response_status' => null,
                    'response_body' => null,
                    'success' => false,
                    'error_message' => 'Bearer token inválido: '.$e->getMessage(),
                    'source' => 'job',
                ]);

                return;
            }

            if (is_string($token) && $token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request->post($webhook->url);
            $responseStatus = $response->status();
            $responseBody = $response->body();
            $success = $response->successful();

            WebhookLog::create([
                'webhook_id' => $webhook->id,
                'event' => $eventSlug,
                'event_label' => $eventLabel,
                'request_payload' => $body,
                'response_status' => $responseStatus,
                'response_body' => strlen($responseBody) > 2000 ? substr($responseBody, 0, 2000) . '…' : $responseBody,
                'success' => $success,
                'error_message' => $success ? null : 'HTTP ' . $responseStatus,
                'source' => 'job',
            ]);

            if (! $success) {
                if ($this->job) {
                    $this->release($this->backoff);
                }
            }
        } catch (\Throwable $e) {
            WebhookLog::create([
                'webhook_id' => $webhook->id,
                'event' => $eventSlug,
                'event_label' => $eventLabel,
                'request_payload' => $body,
                'response_status' => null,
                'response_body' => null,
                'success' => false,
                'error_message' => $e->getMessage(),
                'source' => 'job',
            ]);
            if ($this->job) {
                $this->release($this->backoff);
            }
        }
    }
}
