<?php

namespace App\Console\Commands;

use App\Gateways\GatewayRegistry;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\PendingPaymentReconcileSchedule;
use Illuminate\Console\Command;

class ReconcilePendingPaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-pending
                            {--limit=50 : Máximo de pedidos para consultar no gateway por execução}
                            {--days=30 : Considerar pedidos criados nos últimos X dias}';

    protected $description = 'Reconfirma pagamentos pendentes no gateway e aprova automaticamente quando liquidado.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $days = max(1, (int) $this->option('days'));
        // Varre mais candidatos para não gastar o limit só com pedidos já esgotados (5/10/15).
        $scanLimit = min(2000, max($limit * 10, $limit));

        $orders = Order::query()
            ->where('status', 'pending')
            ->whereNotNull('gateway')
            ->where('gateway', '!=', '')
            ->whereNotNull('gateway_id')
            ->where('gateway_id', '!=', '')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('updated_at')
            ->limit($scanLimit)
            ->get();

        $checked = 0;
        $paid = 0;
        $cancelled = 0;
        $expired = 0;

        foreach ($orders as $order) {
            if ($checked >= $limit) {
                break;
            }

            if (PendingPaymentReconcileSchedule::shouldExpirePix($order)) {
                $this->expirePixOrder($order);
                $expired++;

                continue;
            }

            if (! PendingPaymentReconcileSchedule::isDue($order)) {
                continue;
            }

            $gatewaySlug = is_string($order->gateway) ? $order->gateway : '';
            $transactionId = is_string($order->gateway_id) ? $order->gateway_id : (string) $order->gateway_id;

            if ($gatewaySlug === '' || $transactionId === '') {
                continue;
            }

            $credential = GatewayCredential::forTenant($order->tenant_id)
                ->where('gateway_slug', $gatewaySlug)
                ->where('is_connected', true)
                ->first();

            if (! $credential) {
                continue;
            }

            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }

            $credentials = $credential->getDecryptedCredentials();
            if ($credentials === []) {
                continue;
            }

            $checked++;

            try {
                $apiStatus = $driver->getTransactionStatus($transactionId, $credentials);
                // CajuPay: se gateway_id for payment_id e a API pública da sessão tiver o paid,
                // tenta também o token/session id do metadata.
                if ($apiStatus !== 'paid' && $gatewaySlug === 'cajupay') {
                    $meta = is_array($order->metadata) ? $order->metadata : [];
                    foreach (['cajupay_session_token', 'cajupay_checkout_session_id', 'cajupay_payment_id'] as $metaKey) {
                        $alt = isset($meta[$metaKey]) && is_string($meta[$metaKey]) ? trim($meta[$metaKey]) : '';
                        if ($alt === '' || $alt === $transactionId) {
                            continue;
                        }
                        try {
                            $altStatus = $driver->getTransactionStatus($alt, $credentials);
                        } catch (\Throwable) {
                            $altStatus = null;
                        }
                        if ($altStatus === 'paid') {
                            $apiStatus = 'paid';
                            break;
                        }
                    }
                }
            } catch (\Throwable) {
                $apiStatus = null;
            }

            PendingPaymentReconcileSchedule::markChecked($order);

            if ($apiStatus === 'paid') {
                $payload = [
                    'source' => 'reconcile_pending',
                ];
                // CajuPay: poll/reconcile já validou paid — ProcessPaymentWebhook não deve
                // abortar se a 2ª consulta à API oscilar.
                if ($gatewaySlug === 'cajupay') {
                    $payload['getfy_order_id'] = $order->id;
                    $meta = is_array($order->metadata) ? $order->metadata : [];
                    if (! empty($meta['cajupay_checkout_session_id'])) {
                        $payload['cajupay_checkout_session_id'] = $meta['cajupay_checkout_session_id'];
                    }
                }
                ProcessPaymentWebhook::dispatchSync($gatewaySlug, $transactionId, 'order.paid', 'paid', $payload);
                $paid++;

                continue;
            }

            if ($apiStatus === 'cancelled') {
                ProcessPaymentWebhook::dispatchSync($gatewaySlug, $transactionId, 'order.cancelled', 'cancelled', [
                    'source' => 'reconcile_pending',
                ]);
                $cancelled++;
            }
        }

        $this->info("Checados: {$checked} | Pagos: {$paid} | Cancelados: {$cancelled} | Expirados: {$expired}");

        return self::SUCCESS;
    }

    private function expirePixOrder(Order $order): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['cancelled_reason'] = 'reconcile_pix_expired';
        $meta['cancelled_at'] = now()->toIso8601String();
        $order->update([
            'status' => 'cancelled',
            'metadata' => $meta,
        ]);
    }
}
