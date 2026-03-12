<?php

namespace App\Http\Middleware;

use App\Models\MemberNotification;
use App\Models\MemberPushSubscription;
use App\Models\PanelNotification;
use App\Models\Setting;
use App\Plugins\PluginRegistry;
use App\Services\SalesAchievementsService;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $appSettings = $user ? [
            'app_name' => Setting::get('app_name', config('getfy.app_name'), $tenantId),
            'theme_primary' => Setting::get('theme_primary', config('getfy.theme_primary'), $tenantId),
            'app_logo' => Setting::get('app_logo', config('getfy.app_logo'), $tenantId),
            'app_logo_dark' => Setting::get('app_logo_dark', config('getfy.app_logo_dark'), $tenantId),
            'app_logo_icon' => Setting::get('app_logo_icon', config('getfy.app_logo_icon'), $tenantId),
            'app_logo_icon_dark' => Setting::get('app_logo_icon_dark', config('getfy.app_logo_icon_dark'), $tenantId),
            'app_favicon' => Setting::get('app_favicon', '', $tenantId),
            'app_description' => Setting::get('app_description', '', $tenantId),
        ] : null;

        $pageTitle = $this->pageTitleForRoute($request->route()?->getName());

        $pluginNavItems = [];
        $plugins = [];
        $achievementsProgress = null;
        $pushEnabled = false;
        $vapidPublic = null;
        if ($user && $user->canAccessPanel()) {
            $pluginNavItems = PluginRegistry::getMenuItems();
            $vapidPublic = config('getfy.pwa.vapid_public');
            $pushEnabled = ! empty($vapidPublic) && ! empty(config('getfy.pwa.vapid_private'));
            $installed = PluginRegistry::installed();
            $plugins = array_map(fn ($p) => [
                'slug' => $p['slug'],
                'name' => $p['name'],
                'version' => $p['version'],
                'is_enabled' => $p['is_enabled'],
            ], $installed);
            $achievementsProgress = app(SalesAchievementsService::class)->getProgressForTenant($user->tenant_id);
        }

        $notificationsUnreadCount = 0;
        if ($user && $user->canAccessPanel()) {
            $notificationsUnreadCount = PanelNotification::forUser($user->id)->unread()->count();
        }

        $path = $request->path();
        $isMemberArea = str_starts_with($path, 'm/') || $request->attributes->get('member_area_slug');
        $isCheckout = str_starts_with($path, 'c/') || str_starts_with($path, 'checkout') || str_starts_with($path, 'api-checkout');
        $skipPanelPwa = $isMemberArea || $isCheckout;

        $memberNotificationsUnreadCount = 0;
        $memberPushSubscribed = false;
        if ($user && $isMemberArea) {
            $product = $request->route('product') ?? $request->attributes->get('member_area_product');
            if ($product) {
                $memberNotificationsUnreadCount = MemberNotification::forUser($user->id)
                    ->forProduct($product->id)
                    ->unread()
                    ->count();
                $memberPushSubscribed = MemberPushSubscription::where('user_id', $user->id)
                    ->where('product_id', $product->id)
                    ->exists();
            }
        }

        $shared = [
            ...parent::share($request),
            'csrf_token' => $request->session()->token(),
            'app_url' => rtrim(config('app.url'), '/'),
            'pageTitle' => $pageTitle,
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role,
                    'avatar_url' => $user->avatar ? app(StorageService::class)->url($user->avatar) : null,
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'status' => $request->session()->get('status'),
                'zip_unavailable' => $request->session()->get('zip_unavailable'),
                'newly_unlocked_achievements' => $request->session()->get('newly_unlocked_achievements'),
            ],
            'platform' => null,
            'appSettings' => $appSettings,
            'pluginNavItems' => $pluginNavItems,
            'plugins' => $plugins,
            'achievementsProgress' => $achievementsProgress,
            'push_enabled' => $pushEnabled,
            'vapid_public' => $pushEnabled ? $vapidPublic : null,
            'notifications_unread_count' => $notificationsUnreadCount,
            'member_notifications_unread_count' => $memberNotificationsUnreadCount,
            'member_push_subscribed' => $memberPushSubscribed,
        ];

        if (! $skipPanelPwa) {
            $shared['pwa_manifest_url'] = url('/manifest.json');
            $shared['pwa_sw_url'] = url('/painel-sw.js');
        }

        return $shared;
    }

    private function pageTitleForRoute(?string $name): ?string
    {
        $titles = [
            'dashboard' => 'Dashboard',
            'vendas.index' => 'Vendas',
            'produtos.index' => 'Produtos',
            'produtos.create' => 'Novo produto',
            'produtos.edit' => 'Editar produto',
            'cupons.index' => 'Cupons',
            'assinaturas.index' => 'Assinaturas',
            'alunos.index' => 'Alunos',
            'relatorios.index' => 'Relatórios',
            'settings.index' => 'Configurações',
            'profile.index' => 'Meu perfil',
            'integrations.index' => 'Integrações',
            'plugins.index' => 'Plugins',
            'checkout.builder' => 'Editar checkout',
            'usuarios.index' => 'Usuários',
            'usuarios.create' => 'Novo infoprodutor',
            'email-marketing.index' => 'E-mail Marketing',
            'email-marketing.create' => 'Nova campanha',
            'email-marketing.edit' => 'Editar campanha',
            'api-applications.index' => 'API de Pagamentos',
            'api-applications.create' => 'Nova aplicação API',
            'api-applications.edit' => 'Editar aplicação API',
            'conquistas.index' => 'Conquistas',
        ];

        return $name ? ($titles[$name] ?? null) : null;
    }
}
