<?php

namespace App\Console\Commands;

use App\Jobs\SendCheckoutSessionRecoveryEmailJob;
use App\Jobs\SendPendingOrderRecoveryEmailJob;
use App\Models\CheckoutSession;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SendCartRecoveryEmailsCommand extends Command
{
    protected $signature = 'checkout:send-cart-recovery-emails {--limit=200 : Máximo por tipo (sessions e orders) por execução}';

    protected $description = 'Envia e-mails de recuperação de carrinho (10m, 5h, 24h) para sessões abandonadas e pedidos pendentes.';

    private const STAGES = [
        1 => ['key' => '10m', 'delay_minutes' => 10],
        2 => ['key' => '5h', 'delay_minutes' => 300],
        3 => ['key' => '24h', 'delay_minutes' => 1440],
    ];

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $sessionsSent = $this->processCheckoutSessions($limit);
        $ordersSent = $this->processPendingOrders($limit);

        $this->info("Recovery emails queued. Sessions={$sessionsSent} Orders={$ordersSent}");

        return self::SUCCESS;
    }

    private function shouldDispatchSync(): bool
    {
        // Sem worker, enfileirar faria o e-mail nunca sair. Então executa sync quando:
        // - queue.default == sync
        // - OU não existe heartbeat recente do worker (QueueHeartbeatJob)
        // - OU explicitamente em ambientes sem fila confiável
        $default = (string) config('queue.default', 'sync');
        if ($default === 'sync') {
            return true;
        }
        $heartbeat = Cache::get('queue_heartbeat');
        if (! is_string($heartbeat) || $heartbeat === '') {
            return true;
        }
        try {
            $last = \Illuminate\Support\Carbon::parse($heartbeat);
        } catch (\Throwable) {
            return true;
        }
        return $last->lt(now()->subMinutes(3));
    }

    private function processCheckoutSessions(int $limit): int
    {
        $now = now();
        $dispatchSync = $this->shouldDispatchSync();

        $q = CheckoutSession::query()
            ->whereIn('step', [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED])
            ->whereNull('order_id')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('recovery_email_stage', '<', 3)
            ->where(function ($qq) use ($now) {
                $qq->whereNull('recovery_email_next_at')
                    ->orWhere('recovery_email_next_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit($limit);

        $sessions = $q->get();
        $queued = 0;

        foreach ($sessions as $s) {
            $nextStage = ((int) ($s->recovery_email_stage ?? 0)) + 1;
            $meta = self::STAGES[$nextStage] ?? null;
            if (! $meta) {
                continue;
            }

            // Regra base: só começa a contar após a última interação relevante + delay
            $anchor = $s->form_filled_at ?? $s->form_started_at ?? $s->created_at;
            $dueAt = $anchor ? $anchor->copy()->addMinutes((int) $meta['delay_minutes']) : $now;
            if ($dueAt->gt($now)) {
                // agenda para o momento correto
                $s->update(['recovery_email_next_at' => $dueAt]);
                continue;
            }

            $stageKey = (string) $meta['key'];
            if ($dispatchSync) {
                SendCheckoutSessionRecoveryEmailJob::dispatchSync($s->id, $stageKey);
            } else {
                SendCheckoutSessionRecoveryEmailJob::dispatch($s->id, $stageKey);
            }

            $s->update([
                'recovery_email_stage' => $nextStage,
                'recovery_email_last_sent_at' => $now,
                'recovery_email_next_at' => $this->computeNextAtForStage($anchor, $nextStage + 1),
            ]);
            $queued++;
        }

        Log::info('SendCartRecoveryEmailsCommand: sessions queued', ['count' => $queued]);

        return $queued;
    }

    private function processPendingOrders(int $limit): int
    {
        $now = now();
        $dispatchSync = $this->shouldDispatchSync();

        $q = Order::query()
            ->where('status', 'pending')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereIn('metadata->checkout_payment_method', ['pix', 'boleto', 'pix_auto'])
            ->where('recovery_email_stage', '<', 3)
            ->where(function ($qq) use ($now) {
                $qq->whereNull('recovery_email_next_at')
                    ->orWhere('recovery_email_next_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit($limit);

        $orders = $q->get();
        $queued = 0;

        foreach ($orders as $o) {
            $nextStage = ((int) ($o->recovery_email_stage ?? 0)) + 1;
            $meta = self::STAGES[$nextStage] ?? null;
            if (! $meta) {
                continue;
            }

            // Regra base: delay conta desde criação do pedido (quando virou pendente)
            $anchor = $o->created_at;
            $dueAt = $anchor ? $anchor->copy()->addMinutes((int) $meta['delay_minutes']) : $now;
            if ($dueAt->gt($now)) {
                $o->update(['recovery_email_next_at' => $dueAt]);
                continue;
            }

            $stageKey = (string) $meta['key'];
            if ($dispatchSync) {
                SendPendingOrderRecoveryEmailJob::dispatchSync($o->id, $stageKey);
            } else {
                SendPendingOrderRecoveryEmailJob::dispatch($o->id, $stageKey);
            }

            $o->update([
                'recovery_email_stage' => $nextStage,
                'recovery_email_last_sent_at' => $now,
                'recovery_email_next_at' => $this->computeNextAtForStage($anchor, $nextStage + 1),
            ]);
            $queued++;
        }

        Log::info('SendCartRecoveryEmailsCommand: orders queued', ['count' => $queued]);

        return $queued;
    }

    private function computeNextAtForStage($anchor, int $stageNumber): ?\Illuminate\Support\Carbon
    {
        $meta = self::STAGES[$stageNumber] ?? null;
        if (! $meta || ! $anchor) {
            return null;
        }
        return $anchor->copy()->addMinutes((int) $meta['delay_minutes']);
    }
}

