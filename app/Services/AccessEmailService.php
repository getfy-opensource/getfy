<?php

namespace App\Services;

use App\Mail\AccessGrantedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AccessEmailService
{
    public function __construct(
        protected TenantMailConfigService $mailConfig,
        protected MemberAreaResolver $memberAreaResolver
    ) {}

    /**
     * Send access email for an order. Returns true on success, false otherwise.
     */
    public function sendForOrder(Order $order, bool $force = false): bool
    {
        Log::info('AccessEmailService: tentando enviar e-mail de acesso.', ['order_id' => $order->id]);

        $order->loadMissing(['product', 'user']);
        $product = $order->product;
        if (! $product) {
            Log::warning('AccessEmailService: e-mail não enviado — pedido sem produto.', ['order_id' => $order->id]);

            return false;
        }

        $productType = $product->type;

        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            Log::info('AccessEmailService: produto área de membros, resolvendo link e senha.', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'checkout_slug' => $product->checkout_slug,
            ]);
        }

        if ($product->type === Product::TYPE_LINK_PAGAMENTO) {
            Log::info('AccessEmailService: e-mail não enviado — produto é tipo link de pagamento.', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_type' => $productType,
            ]);

            return false;
        }

        $config = $product->checkout_config ?? [];
        $template = array_merge(Product::defaultEmailTemplate(), $config['email_template'] ?? []);
        $subject = (string) ($template['subject'] ?? 'Seu acesso');
        $bodyText = (string) ($template['body_text'] ?? '');
        $bodyHtml = (string) ($template['body_html'] ?? '');

        // Preferir texto simples (UI). Se vazio, cai no HTML legado.
        if (trim($bodyText) !== '') {
            $bodyHtml = $this->wrapAccessTextInPrettyHtml($bodyText, '{link_acesso}');
        } elseif ($bodyHtml === '') {
            $bodyHtml = (string) (Product::defaultEmailTemplate()['body_html'] ?? '');
        }

        $customerEmail = $order->email ?: $order->user?->email;
        if (! $customerEmail || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            Log::warning('AccessEmailService: e-mail não enviado — sem e-mail válido para o pedido.', [
                'order_id' => $order->id,
                'product_type' => $productType,
            ]);

            return false;
        }

        $customerName = $order->user?->name ?? explode('@', $customerEmail)[0] ?? 'Cliente';
        $linkAcesso = $order->user && $product->type === Product::TYPE_AREA_MEMBROS
            ? $this->resolveMemberAreaMagicLink($product, $order->user)
            : $this->resolveLinkAcesso($product);

        if (config('app.debug') && $product->type === Product::TYPE_AREA_MEMBROS) {
            Log::debug('AccessEmailService: link_acesso', ['order_id' => $order->id, 'link' => $linkAcesso]);
        }

        $senha = '';
        $passwordCacheKey = null;
        if ($product->type === Product::TYPE_AREA_MEMBROS && $order->user_id && $order->product_id) {
            $passwordCacheKey = 'access_password.' . $order->user_id . '.' . $order->product_id;
            $decrypted = null;
            $meta = $order->metadata ?? [];
            if (! empty($meta['access_password_temp'])) {
                try {
                    $decrypted = decrypt($meta['access_password_temp']);
                } catch (\Throwable $e) {
                    // ignora erro de decrypt
                }
            }
            if (is_string($decrypted) && $decrypted !== '') {
                $senha = $decrypted;
            } else {
                $cached = Cache::get($passwordCacheKey);
                if (is_string($cached) && $cached !== '') {
                    $senha = $cached;
                }
            }
            Log::info('AccessEmailService: área de membros — senha (metadata ou cache).', [
                'order_id' => $order->id,
                'senha_from_metadata' => isset($meta['access_password_temp']),
                'senha_encontrada' => $senha !== '',
            ]);
        }

        // Usar o tenant do produto como fonte de verdade
        $tenantIdForMail = $order->tenant_id ?? $product->tenant_id;
        $isRenewal = (bool) $order->is_renewal;

        if ($isRenewal) {
            // Renovação: enviar e-mail de sucesso (não de acesso). Evitar duplicado.
            $cacheKey = 'access_email_sent.' . $order->id;
            if (! $force && ! Cache::add($cacheKey, true, now()->addHours(24))) {
                Log::info('AccessEmailService: e-mail de renovação já enviado (evitando duplicado).', [
                    'order_id' => $order->id,
                    'product_type' => $product->type,
                    'tenant_id_for_mail' => $tenantIdForMail,
                ]);

                return true;
            }
            $subject = 'Renovação confirmada — ' . $product->name;
            $bodyHtml = $this->buildRenewalSuccessBody($customerName, $product->name);
        } elseif ($product->type === Product::TYPE_AREA_MEMBROS_EXTERNA) {
            // Entrega externa: não enviar e-mail de acesso (link/senha).
            $cacheKey = 'access_email_sent.' . $order->id;
            if (! $force && ! Cache::add($cacheKey, true, now()->addHours(1))) {
                Log::info('AccessEmailService: e-mail (entrega externa) já enviado (evitando duplicado).', [
                    'order_id' => $order->id,
                    'product_type' => $product->type,
                    'tenant_id_for_mail' => $tenantIdForMail,
                ]);

                return true;
            }

            $subject = 'Compra confirmada — ' . $product->name;
            $bodyHtml = $this->buildExternalMemberAreaPendingBody($customerName, $product->name);
        } else {
            // Compra única / nova assinatura: evitar envio duplicado (webhook pode disparar OrderCompleted mais de uma vez)
            $cacheKey = 'access_email_sent.' . $order->id;
            if (! $force && ! Cache::add($cacheKey, true, now()->addHours(1))) {
                Log::info('AccessEmailService: e-mail de acesso já enviado (evitando duplicado).', [
                    'order_id' => $order->id,
                    'product_type' => $product->type,
                    'tenant_id_for_mail' => $tenantIdForMail,
                ]);

                return true;
            }
            $bodyHtmlBeforeReplace = $bodyHtml;
            $bodyTextBeforeReplace = $bodyText;
            $replace = [
                '{nome_cliente}' => $customerName,
                '{nome_produto}' => $product->name,
                '{link_acesso}' => $linkAcesso,
                '{email_cliente}' => $customerEmail,
                '{senha}' => $senha,
            ];
            $subject = str_replace(array_keys($replace), array_values($replace), $subject);
            if (trim($bodyTextBeforeReplace) !== '') {
                $text = str_replace(array_keys($replace), array_values($replace), $bodyTextBeforeReplace);
                $bodyHtml = $this->wrapAccessTextInPrettyHtml($text, $linkAcesso);
            } else {
                $bodyHtml = str_replace(array_keys($replace), array_values($replace), $bodyHtml);
            }
            if (! empty($template['logo_url'])) {
                $bodyHtml = $this->prependLogoToBody($template['logo_url'], $bodyHtml);
            }
            if ($product->type === Product::TYPE_AREA_MEMBROS
                && $senha !== ''
                && ! str_contains($bodyTextBeforeReplace !== '' ? $bodyTextBeforeReplace : $bodyHtmlBeforeReplace, '{senha}')
            ) {
                $bodyHtml = $this->appendMemberAreaPasswordCredentialsBlock($bodyHtml, $customerEmail, $senha);
            }
        }

        try {
            try {
                $this->mailConfig->applyMailerConfigForTenant($tenantIdForMail, [], null);
            } catch (\Throwable $e) {
                Log::error('AccessEmailService: e-mail não enviado — falha ao aplicar config de e-mail do tenant (Hostinger/SMTP/SendGrid).', [
                    'order_id' => $order->id,
                    'product_type' => $productType,
                    'tenant_id_for_mail' => $tenantIdForMail,
                    'message' => $e->getMessage(),
                ]);

                return false;
            }

            $fromAddress = config('mail.from.address');
            $fromName = ! empty($template['from_name']) ? $template['from_name'] : (config('mail.from.name') ?? '');

            Log::info('AccessEmailService: enviando com provedor.', [
                'order_id' => $order->id,
                'product_type' => $productType,
                'tenant_id_for_mail' => $tenantIdForMail,
                'provider' => $this->mailConfig->getProviderForTenant($tenantIdForMail),
                'host' => config('mail.mailers.smtp.host'),
                'from' => $fromAddress,
                'from_name' => $fromName,
            ]);
            Mail::purge('smtp');

            $mailable = new AccessGrantedMail($subject, $bodyHtml);
            $mailable->from($fromAddress, $fromName);
            Mail::mailer('smtp')->to($customerEmail)->send($mailable);

            Log::info($isRenewal ? 'AccessEmailService: e-mail de renovação enviado.' : 'AccessEmailService: e-mail de acesso enviado.', [
                'order_id' => $order->id,
                'product_type' => $productType,
                'tenant_id_for_mail' => $tenantIdForMail,
                'to' => $customerEmail,
            ]);

            if ($passwordCacheKey !== null) {
                Cache::forget($passwordCacheKey);
            }

            $meta = $order->metadata ?? [];
            if (! empty($meta['access_password_temp'])) {
                unset($meta['access_password_temp']);
                $order->update(['metadata' => $meta]);
            }

            return true;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $context = [
                'order_id' => $order->id,
                'product_type' => $productType,
                'tenant_id_for_mail' => $tenantIdForMail,
                'message' => $message,
            ];
            if (str_contains($message, '554') && str_contains($message, 'hPanel')) {
                $context['hint'] = 'Hostinger rejeitou o envio: conta/SMTP pode estar desativada no hPanel. Ative a conta de e-mail e o envio por SMTP em Email no hPanel.';
            }
            Log::error('AccessEmailService: e-mail não enviado — exceção ao enviar.', $context);

            return false;
        }
    }

    /**
     * Return the access link for an order (same link used in the access email).
     * For TYPE_LINK: deliverable_link from config; for TYPE_AREA_MEMBROS: base URL (custom domain or /m/slug).
     */
    public function getAccessLinkForOrder(Order $order): string
    {
        $order->loadMissing(['product']);
        $product = $order->product;
        if (! $product) {
            return '';
        }

        return $this->resolveLinkAcesso($product);
    }

    /**
     * Build access data for WhatsApp delivery (best-effort).
     *
     * @return array{type:string, link:string, email:string, password:string, product_type:string}|null
     */
    public function getAccessDataForOrder(Order $order): ?array
    {
        $order->loadMissing(['product', 'user']);
        $product = $order->product;
        if (! $product) return null;

        // Same exclusions used by access e-mail logic.
        if ($product->type === Product::TYPE_LINK_PAGAMENTO) {
            return null;
        }

        $email = (string) ($order->email ?: $order->user?->email ?: '');
        $link = $this->getAccessLinkForOrder($order);
        if ($link === '' || $email === '') {
            // For TYPE_AREA_MEMBROS_EXTERNA we do not have a usable link here.
            return null;
        }

        $password = '';
        if ($product->type === Product::TYPE_AREA_MEMBROS && $order->user_id && $order->product_id) {
            $passwordCacheKey = 'access_password.' . $order->user_id . '.' . $order->product_id;
            $decrypted = null;
            $meta = $order->metadata ?? [];
            if (! empty($meta['access_password_temp'])) {
                try {
                    $decrypted = decrypt($meta['access_password_temp']);
                } catch (\Throwable) {
                    // ignore
                }
            }
            if (is_string($decrypted) && $decrypted !== '') {
                $password = $decrypted;
            } else {
                $cached = Cache::get($passwordCacheKey);
                if (is_string($cached) && $cached !== '') {
                    $password = $cached;
                }
            }
        }

        $type = $product->type === Product::TYPE_AREA_MEMBROS ? 'member_area' : ($product->type === Product::TYPE_LINK ? 'link' : 'generic');

        return [
            'type' => $type,
            'link' => $link,
            'email' => $email,
            'password' => $password,
            'product_type' => (string) $product->type,
        ];
    }

    /**
     * Send access email for a user who was manually granted access to a product.
     */
    public function sendForUserProduct(User $user, Product $product): bool
    {
        if ($product->type === Product::TYPE_LINK_PAGAMENTO) {
            return false;
        }

        $config = $product->checkout_config ?? [];
        $template = array_merge(Product::defaultEmailTemplate(), $config['email_template'] ?? []);
        $subject = (string) ($template['subject'] ?? 'Seu acesso');
        $bodyText = (string) ($template['body_text'] ?? '');
        $bodyHtml = (string) ($template['body_html'] ?? '');

        if (trim($bodyText) !== '') {
            $bodyHtml = $this->wrapAccessTextInPrettyHtml($bodyText, '{link_acesso}');
        } elseif ($bodyHtml === '') {
            return false;
        }

        $customerEmail = $user->email;
        if (! $customerEmail || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $customerName = $user->name ?: explode('@', $customerEmail)[0] ?? 'Cliente';
        $linkAcesso = $product->type === Product::TYPE_AREA_MEMBROS
            ? $this->resolveMemberAreaMagicLink($product, $user)
            : $this->resolveLinkAcesso($product);

        $replace = [
            '{nome_cliente}' => $customerName,
            '{nome_produto}' => $product->name,
            '{link_acesso}' => $linkAcesso,
            '{email_cliente}' => $customerEmail,
            '{senha}' => '',
        ];
        $subject = str_replace(array_keys($replace), array_values($replace), $subject);
        if (trim($bodyText) !== '') {
            $text = str_replace(array_keys($replace), array_values($replace), $bodyText);
            $bodyHtml = $this->wrapAccessTextInPrettyHtml($text, $linkAcesso);
        } else {
            $bodyHtml = str_replace(array_keys($replace), array_values($replace), $bodyHtml);
        }

        if (! empty($template['logo_url'])) {
            $bodyHtml = $this->prependLogoToBody($template['logo_url'], $bodyHtml);
        }

        try {
            $this->mailConfig->applyMailerConfigForTenant($product->tenant_id, [], null);

            $mailable = new AccessGrantedMail($subject, $bodyHtml);
            if (! empty($template['from_name'])) {
                $mailable->from(config('mail.from.address'), $template['from_name']);
            }
            Mail::mailer('smtp')->to($customerEmail)->send($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::error('AccessEmailService: falha ao enviar e-mail de acesso (manual).', [
                'user_id' => $user->id,
                'product_id' => $product->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveLinkAcesso(Product $product): string
    {
        if ($product->type === Product::TYPE_LINK) {
            $config = $product->checkout_config ?? [];
            $link = $config['deliverable_link'] ?? '';

            return is_string($link) ? $link : '';
        }
        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            $slug = $product->checkout_slug ?? '';
            if ($slug !== '') {
                try {
                    return $this->memberAreaResolver->baseUrlForProduct($product);
                } catch (\Throwable $e) {
                    Log::warning('AccessEmailService: baseUrlForProduct falhou, usando fallback.', [
                        'product_id' => $product->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                $appUrl = rtrim(config('app.url'), '/');

                return $appUrl.'/m/'.$slug;
            }
        }

        return '';
    }

    private function resolveMemberAreaMagicLink(Product $product, User $user): string
    {
        $base = $this->memberAreaResolver->baseUrlForProduct($product);
        $expiresAt = now()->addDays(7);
        $appUrl = rtrim((string) config('app.url'), '/');
        $appScheme = parse_url($appUrl, PHP_URL_SCHEME) ?: null;

        $useHostAccess = true;
        $path = parse_url($base, PHP_URL_PATH);
        if (is_string($path) && str_starts_with(trim($path, '/'), 'm/')) {
            $useHostAccess = false;
        }

        $slugForSignedPathAccess = null;
        if (! $useHostAccess) {
            $basePath = parse_url($base, PHP_URL_PATH);
            if (is_string($basePath) && $basePath !== '') {
                $segments = explode('/', trim($basePath, '/'));
                if (($segments[0] ?? null) === 'm' && ! empty($segments[1])) {
                    $slugForSignedPathAccess = (string) $segments[1];
                }
            }
            if ($slugForSignedPathAccess === null || $slugForSignedPathAccess === '') {
                $slugForSignedPathAccess = (string) ($product->checkout_slug ?? '');
            }
        }

        $originalRoot = $appUrl;
        $originalScheme = $appScheme;

        try {
            if ($useHostAccess) {
                $scheme = parse_url($base, PHP_URL_SCHEME);
                if (is_string($scheme) && $scheme !== '') {
                    \Illuminate\Support\Facades\URL::forceScheme($scheme);
                }
                \Illuminate\Support\Facades\URL::forceRootUrl(rtrim($base, '/'));
                return \Illuminate\Support\Facades\URL::temporarySignedRoute('member-area.magic-access.host', $expiresAt, [
                    'u' => $user->id,
                    'p' => $product->id,
                ]);
            }

            return \Illuminate\Support\Facades\URL::temporarySignedRoute('member-area.magic-access', $expiresAt, [
                'slug' => $slugForSignedPathAccess,
                'u' => $user->id,
                'p' => $product->id,
            ]);
        } finally {
            \Illuminate\Support\Facades\URL::forceRootUrl($originalRoot);
            if (is_string($originalScheme) && $originalScheme !== '') {
                \Illuminate\Support\Facades\URL::forceScheme($originalScheme);
            }
        }
    }

    private function prependLogoToBody(string $logoUrl, string $bodyHtml): string
    {
        $img = '<div style="text-align:center;margin-bottom:20px"><img src="'.e($logoUrl).'" alt="Logo" style="max-height:60px;width:auto" /></div>';

        return $img.$bodyHtml;
    }

    private function appendMemberAreaPasswordCredentialsBlock(string $bodyHtml, string $email, string $password): string
    {
        $block = '<div style="margin:24px 0 0;padding:20px;background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;">'
            .'<p style="margin:0 0 10px;font-size:14px;line-height:1.5;color:#92400e;"><strong>Guarde seus dados de acesso</strong></p>'
            .'<p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#78350f;">O botão de acesso acima entra automaticamente na sua conta. Se você sair ou usar outro aparelho, faça login na área de membros com:</p>'
            .'<p style="margin:0 0 10px;font-size:14px;color:#0f172a;"><strong>E-mail:</strong> '.e($email).'</p>'
            .'<p style="margin:0;font-size:15px;color:#0f172a;font-family:Consolas,\'Courier New\',monospace;font-weight:600;letter-spacing:0.02em;word-break:break-all;"><strong>Senha:</strong> '.e($password).'</p>'
            .'</div>';

        return $bodyHtml.$block;
    }

    private function wrapAccessTextInPrettyHtml(string $text, string $accessUrl): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe = str_replace(["\r\n", "\r"], "\n", $safe);
        $paragraphs = array_values(array_filter(array_map('trim', explode("\n\n", $safe)), fn ($p) => $p !== ''));
        $htmlParagraphs = '';
        foreach ($paragraphs as $p) {
            $p = nl2br($p, false);
            $htmlParagraphs .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.65;color:#334155;">' . $p . '</p>';
        }

        $urlSafe = htmlspecialchars($accessUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $buttonLabel = Str::contains($accessUrl, '/m/') ? 'Acessar agora' : 'Acessar link';

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;"><tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);"><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:28px 32px;">'
            . $htmlParagraphs
            . '<p style="margin:0 0 22px;text-align:center;"><a href="' . $urlSafe . '" style="display:inline-block;padding:14px 32px;background:#0ea5e9;color:#ffffff;text-decoration:none;font-weight:700;font-size:16px;border-radius:10px;">' . htmlspecialchars($buttonLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>'
            . '<p style="margin:0 0 18px;font-size:13px;line-height:1.5;color:#64748b;">Se o botão não abrir, copie e cole no navegador:<br/><a href="' . $urlSafe . '" style="color:#0ea5e9;word-break:break-all;">' . $urlSafe . '</a></p>'
            . '<p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">Qualquer dúvida, responda este e-mail.</p>'
            . '</td></tr></table></td></tr></table>';
    }

    private function buildRenewalSuccessBody(string $customerName, string $productName): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;"><tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);"><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 32px 24px;text-align:center;border-bottom:1px solid #e2e8f0;"><h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá, '.e($customerName).'!</h1></td></tr><tr><td style="padding:28px 32px;"><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Sua renovação da assinatura de <strong>'.e($productName).'</strong> foi confirmada com sucesso.</p><p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">Você continua com acesso total ao conteúdo. Não é necessário fazer nada.</p></td></tr><tr><td style="padding:20px 32px;background:#f1f5f9;border-radius:0 0 12px 12px;"><p style="margin:0;font-size:13px;color:#64748b;">Qualquer dúvida, responda este e-mail.</p></td></tr></table></td></tr></table>';
    }

    private function buildExternalMemberAreaPendingBody(string $customerName, string $productName): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;"><tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);"><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 32px 24px;text-align:center;border-bottom:1px solid #e2e8f0;"><h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá, '.e($customerName).'!</h1></td></tr><tr><td style="padding:28px 32px;"><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Seu pagamento de <strong>'.e($productName).'</strong> foi confirmado.</p><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Este produto é entregue em uma <strong>área de membros externa</strong>. Em instantes você receberá o acesso.</p><p style="margin:0;font-size:14px;line-height:1.6;color:#64748b;">Se você não receber o acesso em alguns minutos, entre em contato com o suporte do vendedor.</p></td></tr><tr><td style="padding:20px 32px;background:#f1f5f9;border-radius:0 0 12px 12px;"><p style="margin:0;font-size:13px;color:#64748b;">Qualquer dúvida, responda este e-mail.</p></td></tr></table></td></tr></table>';
    }
}
