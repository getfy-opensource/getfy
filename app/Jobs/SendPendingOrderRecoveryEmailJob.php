<?php

namespace App\Jobs;

use App\Mail\CartRecoveryMail;
use App\Models\Order;
use App\Models\Product;
use App\Services\TenantMailConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPendingOrderRecoveryEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $orderId,
        public string $stageKey
    ) {}

    public function handle(TenantMailConfigService $mailConfig): void
    {
        $order = Order::with(['product', 'user'])->find($this->orderId);
        if (! $order) {
            return;
        }

        // Revalida elegibilidade (idempotência)
        if ($order->status !== 'pending') {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $method = strtolower((string) ($meta['checkout_payment_method'] ?? ''));
        if (! in_array($method, ['pix', 'boleto', 'pix_auto'], true)) {
            return;
        }

        $email = (string) ($order->email ?? $order->user?->email ?? '');
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $product = $order->product;
        if (! $product) {
            return;
        }

        $config = $product->checkout_config ?? [];
        $recovery = is_array($config['cart_recovery_email'] ?? null) ? $config['cart_recovery_email'] : [];
        if (empty($recovery['enabled'])) {
            return;
        }

        $stages = is_array($recovery['stages'] ?? null) ? $recovery['stages'] : [];
        $stage = is_array($stages[$this->stageKey] ?? null) ? $stages[$this->stageKey] : null;
        if (! $stage) {
            return;
        }

        $subjectTpl = (string) ($stage['subject'] ?? '');
        $bodyTpl = (string) ($stage['body_html'] ?? '');
        if (trim($subjectTpl) === '' || trim($bodyTpl) === '') {
            return;
        }

        $checkoutUrl = $this->buildCheckoutUrlForOrder($order, $product);
        $customerName = trim((string) ($order->user?->name ?? ''));
        if ($customerName === '') {
            $customerName = explode('@', $email)[0] ?? 'Cliente';
        }

        $replace = [
            '{nome_cliente}' => $customerName,
            '{email_cliente}' => $email,
            '{nome_produto}' => (string) ($product->name ?? 'Produto'),
            '{valor}' => $this->formatBrl((float) ($order->amount ?? 0)),
            '{link_checkout}' => $checkoutUrl,
        ];

        $subject = str_replace(array_keys($replace), array_values($replace), $subjectTpl);
        $body = str_replace(array_keys($replace), array_values($replace), $bodyTpl);

        try {
            $mailConfig->applyMailerConfigForTenant($order->tenant_id ?? $product->tenant_id, [], null);
            Mail::purge('smtp');
            Mail::mailer('smtp')->to($email)->send(new CartRecoveryMail($subject, $body));
        } catch (\Throwable $e) {
            Log::warning('SendPendingOrderRecoveryEmailJob: falha ao enviar', [
                'order_id' => $order->id,
                'tenant_id' => $order->tenant_id,
                'stage' => $this->stageKey,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function buildCheckoutUrlForOrder(Order $order, Product $product): string
    {
        $slug = trim((string) $order->getCheckoutSlug());
        if ($slug === '') {
            $slug = trim((string) ($product->checkout_slug ?? ''));
        }

        $base = url('/c/' . $slug);
        $params = array_filter([
            'email' => (string) ($order->email ?? ''),
            'name' => (string) ($order->user?->name ?? ''),
            'phone' => (string) ($order->phone ?? ''),
            'cpf' => (string) ($order->cpf ?? ''),
        ], fn ($v) => is_string($v) && trim($v) !== '');

        return $params ? ($base . '?' . http_build_query($params)) : $base;
    }

    private function formatBrl(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}

