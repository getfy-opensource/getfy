<?php

namespace App\Jobs;

use App\Services\PanelPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPanelPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public ?int $tenantId,
        public string $type,
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $eventKey = null
    ) {}

    public function handle(PanelPushService $panelPushService): void
    {
        try {
            $panelPushService->sendAndPersistToTenant(
                $this->tenantId,
                $this->type,
                $this->title,
                $this->body,
                $this->url,
                $this->eventKey
            );
        } catch (\Throwable $e) {
            Log::warning('SendPanelPushJob: failed', [
                'tenant_id' => $this->tenantId,
                'type' => $this->type,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

