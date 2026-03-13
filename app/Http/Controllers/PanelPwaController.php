<?php

namespace App\Http\Controllers;

use App\Models\PanelPushSubscription;
use App\Models\Setting;
use App\Services\MemberAreaResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelPwaController extends Controller
{
    public function manifest(Request $request): JsonResponse
    {
        $resolved = app(MemberAreaResolver::class)->resolve($request);
        if ($resolved && in_array($resolved['access_type'], ['subdomain', 'custom'], true)) {
            $request->attributes->set('member_area_product', $resolved['product']);
            $request->attributes->set('member_area_access_type', $resolved['access_type']);
            $request->attributes->set('member_area_slug', $resolved['slug']);

            return app()->call(\App\Http\Controllers\MemberAreaAppController::class.'@manifest', [
                'request' => $request,
                'slug' => $resolved['slug'],
            ]);
        }

        $tenantId = auth()->user()?->tenant_id;
        $appName = Setting::get('app_name', config('getfy.app_name', 'Getfy'), $tenantId);
        $themeColor = Setting::get('theme_primary', config('getfy.theme_primary', '#0ea5e9'), $tenantId);

        $icons = [];
        $addIconVariants = function (string $src, string $sizes) use (&$icons): void {
            $icons[] = ['src' => $src, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => $src, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'maskable'];
        };
        $iconsDir = public_path('icons');
        $has192 = is_file($iconsDir.'/icon-192x192.png');
        $has512 = is_file($iconsDir.'/icon-512x512.png');
        $icon192Url = url('/icons/icon-192x192.png');
        $icon512Url = url('/icons/icon-512x512.png');

        if ($has192) {
            $addIconVariants($icon192Url, '192x192');
        }
        if ($has512) {
            $addIconVariants($icon512Url, '512x512');
        }
        if (empty($icons)) {
            $fallbackIcon = (string) Setting::get('app_logo_icon', config('getfy.app_logo_icon', 'https://cdn.getfy.cloud/collapsed-logo.png'), $tenantId);
            $addIconVariants($fallbackIcon, '192x192');
            $addIconVariants($fallbackIcon, '512x512');
        } elseif ($has512 && ! $has192) {
            $addIconVariants($icon512Url, '192x192');
        } elseif ($has192 && ! $has512) {
            $addIconVariants($icon192Url, '512x512');
        }

        $manifest = [
            'id' => '/',
            'name' => $appName,
            'short_name' => $appName,
            'start_url' => '/login',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#18181b',
            'theme_color' => $themeColor,
            'prefer_related_applications' => false,
            'icons' => $icons,
        ];

        return response()
            ->json($manifest)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=0, must-revalidate');
    }

    public function pushSubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.auth' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $keys = $validated['keys'];
        $keys['auth'] = $this->normalizeBase64KeyForPush((string) ($keys['auth'] ?? ''));
        $keys['p256dh'] = $this->normalizeBase64KeyForPush((string) ($keys['p256dh'] ?? ''));

        $subscription = PanelPushSubscription::updateOrCreate(
            [
                'endpoint' => $validated['endpoint'],
            ],
            [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'keys' => $keys,
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json([
            'success' => true,
            'subscribed' => true,
            'subscription_id' => $subscription->id,
            'updated_at' => $subscription->updated_at?->toISOString(),
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
}
