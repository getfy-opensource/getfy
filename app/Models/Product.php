<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    public const TYPE_APLICATIVO = 'aplicativo';
    public const TYPE_AREA_MEMBROS = 'area_membros';
    public const TYPE_AREA_MEMBROS_EXTERNA = 'area_membros_externa';
    public const TYPE_LINK = 'link';
    public const TYPE_LINK_PAGAMENTO = 'link_pagamento';

    public const BILLING_ONE_TIME = 'one_time';
    public const BILLING_SUBSCRIPTION = 'subscription';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'checkout_slug',
        'checkout_config',
        'description',
        'type',
        'billing_type',
        'image',
        'price',
        'currency',
        'is_active',
        'conversion_pixels',
        'member_area_config',
        'combo_product_ids',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'checkout_config' => 'array',
            'member_area_config' => 'array',
            'conversion_pixels' => 'array',
            'combo_product_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (empty($product->id)) {
                $product->id = (string) Str::uuid();
            }
            if (empty($product->checkout_slug)) {
                $product->checkout_slug = static::generateUniqueCheckoutSlug();
            }
        });

        static::updating(function (Product $product): void {
            if ($product->isDirty('checkout_slug') === false && empty($product->checkout_slug)) {
                $product->checkout_slug = static::generateUniqueCheckoutSlug();
            }
        });
    }

    public static function generateUniqueCheckoutSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(7));
        } while (static::where('checkout_slug', $slug)->exists());

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultCheckoutConfig(): array
    {
        return [
            'summary' => [
                'previous_price' => null,
                'discount_text' => '',
            ],
            'appearance' => [
                'background_color' => '#E3E3E3',
                'primary_color' => '#0ea5e9',
                'banners' => [],
                'side_banners' => [],
            ],
            'timer' => [
                'enabled' => false,
                'text' => 'Esta oferta expira em:',
                'minutes' => 15,
                'background_color' => '#000000',
                'text_color' => '#FFFFFF',
                'sticky' => true,
            ],
            'sales_notification' => [
                'enabled' => false,
                'title' => '',
                'names_per_line' => 1,
                'names' => '',
                'product_label' => '',
                'display_seconds' => 5,
                'interval_seconds' => 10,
            ],
            'template' => 'original',
            'youtube_url' => null,
            'redirect_after_purchase' => '',
            'back_redirect' => ['enabled' => false, 'url' => ''],
            'customer_fields' => [
                'name' => true,
                'cpf' => true,
                'phone' => true,
                'coupon' => false,
            ],
            'payment_gateways' => [
                'pix' => null,
                'pix_redundancy' => [],
                'card' => null,
                'card_redundancy' => [],
                'boleto' => null,
                'boleto_redundancy' => [],
                'pix_auto' => null,
                'pix_auto_redundancy' => [],
                'crypto' => null,
                'crypto_redundancy' => [],
            ],
            'card_installments' => [
                'enabled' => false,
                'max' => 1,
            ],
            'pagarme_billing' => [
                'mode' => 'customer',
                'company_address' => [
                    'zipcode' => '',
                    'street' => '',
                    'number' => '',
                    'neighborhood' => '',
                    'city' => '',
                    'state' => '',
                ],
            ],
            'stripe_link_enabled' => true,
            'deliverable_link' => '',
            'seo' => [
                'title' => '',
                'description' => '',
                'og_image' => null,
                'favicon' => null,
            ],
            'support_button' => [
                'enabled' => false,
                'text' => 'Suporte',
                'icon' => 'whatsapp',
                'position' => 'bottom-right',
                'url' => '',
                'color' => '#25D366',
            ],
            'footer' => [
                'enabled' => false,
                'logo_url' => '',
                'support_email' => '',
                'text' => '',
            ],
            'exit_popup' => [
                'enabled' => false,
                'triggers' => [
                    'back_button' => true,
                    'tab_switch' => true,
                    'mouse_leave_top' => false,
                    'timer_seconds' => null,
                ],
                'coupon_id' => null,
                'image' => null,
                'frequency_per_session' => 1,
                'title' => 'Espere! Temos um desconto para você',
                'description' => 'Use o cupom abaixo na próxima etapa',
                'button_accept' => 'Quero o desconto',
                'button_decline' => 'Não, obrigado',
            ],
            'reviews' => [],
            'upsell' => [
                'enabled' => false,
                'products' => [],
                'page' => [
                    'headline' => 'Quer levar isso também?',
                    'subheadline' => 'Uma oferta exclusiva preparada para você',
                    'body_text' => '',
                    'hero_image' => null,
                    'hero_video_url' => null,
                    'background_color' => '#f3f4f6',
                    'background_image' => null,
                    'show_product_just_bought' => true,
                ],
                'appearance' => [
                    'title' => 'Quer levar isso também?',
                    'subtitle' => 'Uma oferta exclusiva preparada para você',
                    'primary_color' => '#0ea5e9',
                    'button_accept' => 'Sim, quero aproveitar',
                    'button_decline' => 'Não, obrigado',
                ],
            ],
            'downsell' => [
                'enabled' => false,
                'product_id' => null,
                'product_offer_id' => null,
                'page' => [
                    'headline' => 'Última chance com desconto',
                    'subheadline' => 'Uma oferta que não pode ficar de fora',
                    'body_text' => '',
                    'hero_image' => null,
                    'hero_video_url' => null,
                    'background_color' => '#f3f4f6',
                    'background_image' => null,
                    'show_product_just_bought' => true,
                ],
                'appearance' => [
                    'title' => 'Última chance com desconto',
                    'subtitle' => 'Uma oferta que não pode ficar de fora',
                    'primary_color' => '#0ea5e9',
                    'button_accept' => 'Aceitar oferta',
                    'button_decline' => 'Não, obrigado',
                ],
            ],
            'advanced' => [
                'custom_css' => '',
                'custom_head_html' => '',
                'custom_body_start_html' => '',
                'custom_body_end_html' => '',
                'custom_js' => '',
            ],
        ];
    }

    /**
     * Default structure for the access email template (checkout_config.email_template).
     *
     * @return array{logo_url: string, from_name: string, subject: string, body_html: string}
     */
    public static function defaultEmailTemplate(): array
    {
        return [
            'logo_url' => '',
            'from_name' => '',
            'subject' => 'Seu acesso a {nome_produto}',
            'body_html' => '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;"><tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);"><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 32px 24px;text-align:center;border-bottom:1px solid #e2e8f0;"><h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá, {nome_cliente}!</h1></td></tr><tr><td style="padding:28px 32px;"><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Obrigado por adquirir <strong>{nome_produto}</strong>.</p><p style="margin:0 0 24px;font-size:16px;line-height:1.6;color:#334155;">Clique no botão abaixo para acessar seu conteúdo agora:</p><p style="margin:0 0 24px;text-align:center;"><a href="{link_acesso}" style="display:inline-block;padding:14px 32px;background:#0ea5e9;color:#ffffff;text-decoration:none;font-weight:600;font-size:16px;border-radius:8px;">Acessar agora</a></p><p style="margin:0 0 24px;font-size:14px;line-height:1.5;color:#64748b;">Ou copie e cole no navegador:<br/><a href="{link_acesso}" style="color:#0ea5e9;word-break:break-all;">{link_acesso}</a></p><div style="margin:28px 0 0;padding:20px;background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;"><p style="margin:0 0 10px;font-size:14px;line-height:1.5;color:#92400e;"><strong>Guarde seus dados de acesso</strong></p><p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#78350f;">O botão acima entra automaticamente na sua conta. Se você sair ou usar outro aparelho, faça login na área de membros com:</p><p style="margin:0 0 10px;font-size:14px;color:#0f172a;"><strong>E-mail:</strong> {email_cliente}</p><p style="margin:0;font-size:15px;color:#0f172a;font-family:Consolas,\'Courier New\',monospace;font-weight:600;letter-spacing:0.02em;word-break:break-all;"><strong>Senha:</strong> {senha}</p></div></td></tr><tr><td style="padding:20px 32px;background:#f1f5f9;border-radius:0 0 12px 12px;"><p style="margin:0;font-size:13px;color:#64748b;">Qualquer dúvida, responda este e-mail.</p></td></tr></table></td></tr></table>',
        ];
    }

    public function getCheckoutConfigAttribute(mixed $value): array
    {
        $stored = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : []);
        return array_replace_recursive(static::defaultCheckoutConfig(), $stored ?? []);
    }

    /**
     * Flags padrão por entrada de pixel (checkout / CAPI).
     *
     * @return array{fire_purchase_on_pix: bool, fire_purchase_on_boleto: bool, disable_order_bump_events: bool}
     */
    public static function defaultConversionPixelEntryFlags(): array
    {
        return [
            'fire_purchase_on_pix' => true,
            'fire_purchase_on_boleto' => true,
            'disable_order_bump_events' => false,
        ];
    }

    /**
     * Default structure for conversion pixels (Meta, TikTok, Google Ads, GA4, custom scripts).
     * Plataformas principais usam { enabled, entries: [...] }; legado (objeto plano) é normalizado na leitura.
     *
     * @return array<string, mixed>
     */
    public static function defaultConversionPixels(): array
    {
        $emptyPlatform = ['enabled' => false, 'entries' => []];

        return [
            'meta' => $emptyPlatform,
            'tiktok' => $emptyPlatform,
            'google_ads' => $emptyPlatform,
            'google_analytics' => $emptyPlatform,
            'custom_script' => [],
        ];
    }

    /**
     * Normaliza um bloco de plataforma (novo formato com entries ou legado com pixel_id / conversion_id na raiz).
     *
     * @param  array<string, mixed>  $block
     * @return array{enabled: bool, entries: list<array<string, mixed>>}
     */
    public static function normalizeConversionPixelBlock(array $block, string $platform): array
    {
        $enabled = filter_var($block['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $flagsBase = static::defaultConversionPixelEntryFlags();
        $entries = [];

        $hasEntriesKey = array_key_exists('entries', $block) && is_array($block['entries']);

        if ($hasEntriesKey) {
            foreach ($block['entries'] as $e) {
                if (! is_array($e)) {
                    continue;
                }
                $flags = $flagsBase;
                foreach (['fire_purchase_on_pix', 'fire_purchase_on_boleto', 'disable_order_bump_events'] as $fk) {
                    if (array_key_exists($fk, $e)) {
                        $flags[$fk] = filter_var($e[$fk], FILTER_VALIDATE_BOOLEAN);
                    }
                }
                $id = trim((string) ($e['id'] ?? ''));
                if ($id === '') {
                    $id = Str::uuid()->toString();
                }

                if ($platform === 'meta' || $platform === 'tiktok') {
                    $pixelId = trim((string) ($e['pixel_id'] ?? ''));
                    if ($pixelId === '') {
                        continue;
                    }
                    $entries[] = [
                        'id' => $id,
                        'pixel_id' => $pixelId,
                        'access_token' => trim((string) ($e['access_token'] ?? '')),
                    ] + $flags;
                } elseif ($platform === 'google_ads') {
                    $convId = trim((string) ($e['conversion_id'] ?? ''));
                    if ($convId === '') {
                        continue;
                    }
                    $entries[] = [
                        'id' => $id,
                        'conversion_id' => $convId,
                        'conversion_label' => trim((string) ($e['conversion_label'] ?? '')),
                    ] + $flags;
                } elseif ($platform === 'google_analytics') {
                    $mid = trim((string) ($e['measurement_id'] ?? ''));
                    if ($mid === '') {
                        continue;
                    }
                    $entries[] = [
                        'id' => $id,
                        'measurement_id' => $mid,
                    ] + $flags;
                }
            }
        } else {
            $flags = $flagsBase;
            foreach (['fire_purchase_on_pix', 'fire_purchase_on_boleto', 'disable_order_bump_events'] as $fk) {
                if (array_key_exists($fk, $block)) {
                    $flags[$fk] = filter_var($block[$fk], FILTER_VALIDATE_BOOLEAN);
                }
            }
            $id = Str::uuid()->toString();

            if ($platform === 'meta' || $platform === 'tiktok') {
                $pixelId = trim((string) ($block['pixel_id'] ?? ''));
                $accessToken = trim((string) ($block['access_token'] ?? ''));
                if ($pixelId !== '' || $accessToken !== '') {
                    $entries[] = [
                        'id' => $id,
                        'pixel_id' => $pixelId,
                        'access_token' => $accessToken,
                    ] + $flags;
                }
            } elseif ($platform === 'google_ads') {
                $convId = trim((string) ($block['conversion_id'] ?? ''));
                if ($convId !== '') {
                    $entries[] = [
                        'id' => $id,
                        'conversion_id' => $convId,
                        'conversion_label' => trim((string) ($block['conversion_label'] ?? '')),
                    ] + $flags;
                }
            } elseif ($platform === 'google_analytics') {
                $mid = trim((string) ($block['measurement_id'] ?? ''));
                if ($mid !== '') {
                    $entries[] = [
                        'id' => $id,
                        'measurement_id' => $mid,
                    ] + $flags;
                }
            }
        }

        return [
            'enabled' => $enabled,
            'entries' => array_values($entries),
        ];
    }

    public function getConversionPixelsAttribute(mixed $value): array
    {
        $stored = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : []);
        if (! is_array($stored)) {
            $stored = [];
        }
        foreach (['meta', 'tiktok', 'google_ads', 'google_analytics'] as $key) {
            $raw = isset($stored[$key]) && is_array($stored[$key]) ? $stored[$key] : [];
            $stored[$key] = static::normalizeConversionPixelBlock($raw, $key);
        }
        if (! isset($stored['custom_script']) || ! is_array($stored['custom_script'])) {
            $stored['custom_script'] = [];
        }

        return $stored;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultMemberAreaConfig(): array
    {
        return [
            'theme' => [
                'primary' => '#0ea5e9',
                'background' => '#18181b',
                'sidebar_bg' => '#27272a',
                'text' => '#f8fafc',
                'accent' => '#38bdf8',
            ],
            'hero' => [
                'image_url' => null,
                'image_url_desktop' => null,
                'image_url_mobile' => null,
                'title' => '',
                'subtitle' => '',
                'overlay' => true,
            ],
            'header' => [
                'logo_url' => null,
            ],
            'logos' => [
                'logo_light' => '',
                'logo_dark' => '',
                'favicon' => '',
            ],
            'sidebar' => [
                'collapsible' => true,
                'items' => [
                    ['title' => 'Início', 'icon' => 'home', 'link' => '/', 'open_external' => false],
                ],
            ],
            'login' => [
                'logo' => '',
                'background_image' => '',
                'background_color' => '#18181b',
                'primary_color' => '#0ea5e9',
                'title' => 'Área de Membros',
                'subtitle' => 'Entre com seu e-mail e senha',
                'password_mode' => 'auto',
                'default_password' => '',
                'login_without_password' => false,
            ],
            'pwa' => [
                'name' => '',
                'short_name' => '',
                'theme_color' => '#0ea5e9',
                'push_enabled' => false,
            ],
            'certificate' => [
                'enabled' => false,
                'title' => '',
                'completion_percent' => 100,
                'template_url' => null,
                'signature_text' => '',
                'font_family' => 'sans-serif',
                'duration_text' => '',
            ],
            'comments_enabled' => false,
            'comments_require_approval' => true,
            'gamification' => [
                'enabled' => false,
                'achievements' => [],
            ],
        ];
    }

    public function getMemberAreaConfigAttribute(mixed $value): array
    {
        if ($this->type !== self::TYPE_AREA_MEMBROS) {
            return [];
        }
        $stored = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : []);
        $config = array_replace_recursive(static::defaultMemberAreaConfig(), $stored ?? []);
        // Normalizar tons azulados antigos (slate) para cinza grafite
        $theme = $config['theme'] ?? [];
        if (($theme['background'] ?? '') === '#0f172a') {
            $config['theme']['background'] = '#18181b';
        }
        if (($theme['sidebar_bg'] ?? '') === '#1e293b') {
            $config['theme']['sidebar_bg'] = '#27272a';
        }
        return $config;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'product_user')->withTimestamps();
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }
        return $query->where('tenant_id', $tenantId);
    }

    public function memberAreaDomain(): HasOne
    {
        return $this->hasOne(MemberAreaDomain::class);
    }

    public function memberSections(): HasMany
    {
        return $this->hasMany(MemberSection::class)->orderBy('position');
    }

    public function memberInternalProducts(): HasMany
    {
        return $this->hasMany(MemberInternalProduct::class)->orderBy('position');
    }

    public function memberTurmas(): HasMany
    {
        return $this->hasMany(MemberTurma::class)->orderBy('position');
    }

    public function memberComments(): HasMany
    {
        return $this->hasMany(MemberComment::class);
    }

    public function memberCommunityPages(): HasMany
    {
        return $this->hasMany(MemberCommunityPage::class)->orderBy('position');
    }

    public function memberAchievementUnlocks(): HasMany
    {
        return $this->hasMany(MemberAchievementUnlock::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class)->orderBy('position');
    }

    public function subscriptionPlans(): HasMany
    {
        return $this->hasMany(SubscriptionPlan::class)->orderBy('position');
    }

    public function orderBumps(): HasMany
    {
        return $this->hasMany(ProductOrderBump::class)->orderBy('position');
    }

    public function hasMemberAreaAccess(User $user): bool
    {
        // Admin/Infoprodutor do mesmo tenant do produto tem acesso automático à área de membros
        // (usuário de equipe não deve ganhar acesso automático)
        if (($user->isAdmin() || $user->isInfoprodutor()) && $user->tenant_id === $this->tenant_id) {
            return true;
        }
        return $this->users()->where('user_id', $user->id)->exists();
    }

    /**
     * @return array<string, array{label: string, description: string, available: bool}>
     */
    public static function typeConfig(): array
    {
        return [
            self::TYPE_APLICATIVO => [
                'label' => 'Aplicativo',
                'description' => 'App próprio com PWA. Subdomínio ou domínio customizado (em breve).',
                'available' => false,
            ],
            self::TYPE_AREA_MEMBROS => [
                'label' => 'Área de membros',
                'description' => 'Área exclusiva para alunos com PWA. Subdomínio ou domínio customizado.',
                'available' => true,
            ],
            self::TYPE_AREA_MEMBROS_EXTERNA => [
                'label' => 'Área de membros externa',
                'description' => 'Entrega o acesso em uma plataforma externa (ex.: Cademí) após o pagamento.',
                'available' => true,
            ],
            self::TYPE_LINK => [
                'label' => 'Link',
                'description' => 'Entrega por link único após a compra.',
                'available' => true,
            ],
            self::TYPE_LINK_PAGAMENTO => [
                'label' => 'Somente link de pagamento',
                'description' => 'Apenas gera link de checkout, sem entrega automática.',
                'available' => true,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function billingTypeLabels(): array
    {
        return [
            self::BILLING_ONE_TIME => 'Pagamento único',
            self::BILLING_SUBSCRIPTION => 'Assinatura',
        ];
    }
}
