<?php

namespace App\Services;

use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use App\Models\User;
use App\Support\PanelPushPreferences;
use App\Support\VapidKeysManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class PanelPushService
{
    private const MAX_PUSH_FAIL_COUNT = 5;

    /**
     * Envia push para o tenant e persiste uma notificação por usuário (para o centro de notificações).
     *
     * @param  string  $type  Tipo para o centro de notificações: sale_approved, pix_generated, boleto_generated, etc.
     * @param  string|null  $eventKey  Chave única do evento (ex: order_123). Quando informada, evita duplicar notificação para o mesmo evento.
     * @param  'pix'|'boleto'|'card'  $category  Categoria para filtrar preferências do usuário (PIX, boleto, cartão/wallets).
     */
    public function sendAndPersistToTenant(
        ?int $tenantId,
        string $type,
        string $title,
        string $body,
        ?string $url = null,
        ?string $eventKey = null,
        string $category = 'pix'
    ): int {
        $subscriptions = PanelPushSubscription::where('tenant_id', $tenantId)->get();
        $userIds = $subscriptions->pluck('user_id')->unique()->filter()->values();

        foreach ($userIds as $userId) {
            $attrs = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ];
            if ($eventKey !== null && $eventKey !== '') {
                PanelNotification::firstOrCreate(
                    [
                        'user_id' => $userId,
                        'event_key' => $eventKey,
                    ],
                    array_merge($attrs, ['event_key' => $eventKey])
                );
            } else {
                PanelNotification::create($attrs);
            }
        }

        $dedupeKey = $this->pushDedupeCacheKey($tenantId, $eventKey);
        if ($dedupeKey !== null && ! Cache::add($dedupeKey, true, now()->addDays(7))) {
            Log::info('PanelPushService: push ignorado (evento já enviado)', [
                'tenant_id' => $tenantId,
                'event_key' => $eventKey,
                'type' => $type,
            ]);

            return 0;
        }

        $sent = $this->sendToTenant($tenantId, $title, $body, $url, $category, $eventKey);

        return $sent;
    }

    /**
     * @param  'pix'|'boleto'|'card'  $category
     */
    public function sendToTenant(
        ?int $tenantId,
        string $title,
        string $body,
        ?string $url = null,
        string $category = 'pix',
        ?string $notificationTag = null
    ): int {
        $subscriptions = PanelPushSubscription::where('tenant_id', $tenantId)->get();
        $subscriptions = $subscriptions->filter(function (PanelPushSubscription $sub) use ($category) {
            $prefs = PanelPushPreferences::normalize($sub->preferences);

            return PanelPushPreferences::allowsCategory($prefs, $category);
        })->values();
        if ($subscriptions->isEmpty()) {
            Log::warning('PanelPushService: nenhuma inscrição push para o tenant (usuário deve permitir notificações no painel)', ['tenant_id' => $tenantId]);
            return 0;
        }

        return $this->deliver($subscriptions, $tenantId, $title, $body, $url, $notificationTag);
    }

    /**
     * Push de teste (botão da central de notificações): só os dispositivos do próprio usuário, ignora as
     * preferências por categoria e não grava no histórico.
     *
     * @return array{sent: int, subscriptions: int}
     */
    public function sendTestToUser(User $user): array
    {
        $subscriptions = PanelPushSubscription::where('user_id', $user->id)
            ->where('tenant_id', $user->tenant_id)
            ->get();
        if ($subscriptions->isEmpty()) {
            return ['sent' => 0, 'subscriptions' => 0];
        }

        $sent = $this->deliver(
            $subscriptions,
            $user->tenant_id,
            'Notificação de teste',
            'Se você está vendo isto, as notificações push estão funcionando neste dispositivo.',
            '/dashboard',
            'panel-push-test',
        );

        return ['sent' => $sent, 'subscriptions' => $subscriptions->count()];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PanelPushSubscription>  $subscriptions
     */
    private function deliver(
        \Illuminate\Support\Collection $subscriptions,
        ?int $tenantId,
        string $title,
        string $body,
        ?string $url,
        ?string $notificationTag
    ): int {
        $vapidPair = app(VapidKeysManager::class)->resolveKeyPair();
        $vapidPublic = $vapidPair['public'];
        $vapidPrivate = $vapidPair['private'];

        if (! $vapidPublic || ! $vapidPrivate) {
            Log::warning('PanelPushService: VAPID não configurado (defina PWA_VAPID_PUBLIC e PWA_VAPID_PRIVATE no .env)', ['tenant_id' => $tenantId]);
            return 0;
        }

        $subject = 'mailto:' . (config('mail.from.address') ?: 'noreply@' . parse_url(config('app.url'), PHP_URL_HOST));

        try {
            VAPID::validate([
                'subject' => $subject,
                'publicKey' => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ]);
        } catch (\Throwable $e) {
            Log::error('PanelPushService: par VAPID rejeitado pela lib web-push (chave truncada/corrompida ou subject inválido).', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
                'public_b64url_len' => strlen($vapidPublic),
                'private_b64url_len' => strlen($vapidPrivate),
                'hint' => 'Rode `php artisan pwa:vapid` no container app; confira uma única linha PWA_VAPID_* no .env e em .docker/pwa_vapid.env; reinicie app+queue; reative notificações no PWA após trocar o par.',
            ]);

            return 0;
        }

        $auth = [
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ];

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'tag' => $notificationTag ?: ($url ?: 'panel-push'),
        ]);

        $sent = 0;
        $invalidCount = 0;
        $failedCount = 0;
        $expiredCount = 0;
        try {
            $webPush = $this->makeWebPush($auth);
            foreach ($subscriptions as $sub) {
                $keys = $sub->keys ?? [];
                $authKey = trim((string) ($keys['auth'] ?? ''));
                $p256dh = trim((string) ($keys['p256dh'] ?? ''));
                if (! $sub->endpoint || $authKey === '' || $p256dh === '') {
                    $invalidCount++;
                    Log::warning('PanelPushService: subscription com keys inválidas', ['subscription_id' => $sub->id]);
                    continue;
                }
                $subscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys' => [
                        'auth' => $this->normalizeBase64KeyForPush($authKey),
                        'p256dh' => $this->normalizeBase64KeyForPush($p256dh),
                    ],
                ]);
                try {
                    $report = $webPush->sendOneNotification($subscription, $payload);
                    if ($report->isSuccess()) {
                        $sent++;
                        if ($sub->push_fail_count > 0 || $sub->last_push_failed_at !== null) {
                            $sub->update(['push_fail_count' => 0, 'last_push_failed_at' => null]);
                        }
                    } elseif ($this->shouldRemoveSubscription($report)) {
                        $expiredCount++;
                        $sub->delete();
                        Log::info('PanelPushService: subscription inválida removida', [
                            'subscription_id' => $sub->id,
                            'reason' => $report->getReason(),
                        ]);
                    } else {
                        $failedCount++;
                        $this->recordPushFailure($sub, $report->getReason());
                        Log::warning('PanelPushService: envio falhou', [
                            'subscription_id' => $sub->id,
                            'reason' => $report->getReason(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    $failedCount++;
                    $this->recordPushFailure($sub, $e->getMessage());
                    Log::warning('PanelPushService: falha ao enviar para subscription', [
                        'subscription_id' => $sub->id,
                        'tenant_id' => $sub->tenant_id,
                        'user_id' => $sub->user_id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
            if ($sent > 0) {
                Log::info('PanelPushService: push enviado', ['tenant_id' => $tenantId, 'sent' => $sent]);
            } else {
                Log::warning('PanelPushService: nenhum push entregue para o tenant', [
                    'tenant_id' => $tenantId,
                    'total_subscriptions' => $subscriptions->count(),
                    'invalid_subscriptions' => $invalidCount,
                    'expired_subscriptions' => $expiredCount,
                    'failed_subscriptions' => $failedCount,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('PanelPushService: erro ao enviar push', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
        }

        return $sent;
    }

    /**
     * Normaliza chave para o formato esperado pela minishlink/web-push (evita "Base64::decode() only expects characters in the correct base64 alphabet").
     * Converte base64 padrão (+/) para base64url (-_) se a lib esperar base64url; senão mantém padrão.
     */
    private function shouldRemoveSubscription(\Minishlink\WebPush\MessageSentReport $report): bool
    {
        if ($report->isSubscriptionExpired()) {
            return true;
        }

        $reason = strtolower((string) $report->getReason());

        return str_contains($reason, '410')
            || str_contains($reason, '404')
            || str_contains($reason, 'gone')
            || str_contains($reason, 'expired')
            || str_contains($reason, 'unsubscribed');
    }

    private function recordPushFailure(PanelPushSubscription $sub, ?string $reason): void
    {
        $failCount = (int) $sub->push_fail_count + 1;
        if ($failCount >= self::MAX_PUSH_FAIL_COUNT) {
            $sub->delete();
            Log::info('PanelPushService: subscription removida após falhas repetidas', [
                'subscription_id' => $sub->id,
                'fail_count' => $failCount,
                'reason' => $reason,
            ]);

            return;
        }

        $sub->update([
            'push_fail_count' => $failCount,
            'last_push_failed_at' => now(),
        ]);
    }

    private function normalizeBase64KeyForPush(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return $key;
        }
        if (str_contains($key, '+') || str_contains($key, '/')) {
            return strtr($key, ['+' => '-', '/' => '_']);
        }
        return $key;
    }

    private function pushDedupeCacheKey(?int $tenantId, ?string $eventKey): ?string
    {
        if ($eventKey === null || $eventKey === '') {
            return null;
        }

        return 'panel_push_sent.'.($tenantId ?? '0').'.'.$eventKey;
    }

    /**
     * @param  array<string, mixed>  $auth
     */
    public function makeWebPush(array $auth): WebPush
    {
        return new WebPush($auth);
    }
}
