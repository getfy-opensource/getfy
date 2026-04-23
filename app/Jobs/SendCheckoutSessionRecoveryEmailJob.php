<?php

namespace App\Jobs;

use App\Mail\CartRecoveryMail;
use App\Models\CheckoutSession;
use App\Models\Product;
use App\Services\TenantMailConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCheckoutSessionRecoveryEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $checkoutSessionId,
        public string $stageKey
    ) {}

    public function handle(TenantMailConfigService $mailConfig): void
    {
        $session = CheckoutSession::with('product')->find($this->checkoutSessionId);
        if (! $session) {
            return;
        }

        // Revalida elegibilidade (idempotência)
        if ($session->order_id !== null) {
            return;
        }
        if (! in_array($session->step, [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED], true)) {
            return;
        }
        $email = (string) ($session->email ?? '');
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $product = $session->product;
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

        $checkoutUrl = $this->buildCheckoutUrlForSession($session, $product);
        $name = trim((string) ($session->name ?? ''));
        $customerName = $name !== '' ? $name : (explode('@', $email)[0] ?? 'Cliente');

        $replace = [
            '{nome_cliente}' => $customerName,
            '{email_cliente}' => $email,
            '{nome_produto}' => (string) ($product->name ?? 'Produto'),
            '{valor}' => $this->formatBrl((float) ($product->price ?? 0)),
            '{link_checkout}' => $checkoutUrl,
        ];

        $subject = str_replace(array_keys($replace), array_values($replace), $subjectTpl);
        $body = str_replace(array_keys($replace), array_values($replace), $bodyTpl);

        try {
            $mailConfig->applyMailerConfigForTenant($session->tenant_id ?? $product->tenant_id, [], null);
            Mail::purge('smtp');
            Mail::mailer('smtp')->to($email)->send(new CartRecoveryMail($subject, $body));
        } catch (\Throwable $e) {
            Log::warning('SendCheckoutSessionRecoveryEmailJob: falha ao enviar', [
                'checkout_session_id' => $session->id,
                'tenant_id' => $session->tenant_id,
                'stage' => $this->stageKey,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function buildCheckoutUrlForSession(CheckoutSession $session, Product $product): string
    {
        $slug = trim((string) ($session->checkout_slug ?: $product->checkout_slug));
        $base = url('/c/' . $slug);
        $params = array_filter([
            'email' => $session->email,
            'name' => $session->name,
        ], fn ($v) => is_string($v) && trim($v) !== '');

        return $params ? ($base . '?' . http_build_query($params)) : $base;
    }

    private function formatBrl(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}

