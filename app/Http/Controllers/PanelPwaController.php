<?php

namespace App\Http\Controllers;

use App\Models\PanelPushSubscription;
use App\Services\MemberAreaResolver;
use App\Services\PanelPushService;
use App\Support\PanelPushPreferences;
use App\Support\PwaIcon;
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

        $appName = trim((string) config('getfy.app_name', 'Getfy'));
        if ($appName === '') {
            $appName = 'Getfy';
        }
        $shortName = mb_strlen($appName) > 12 ? mb_substr($appName, 0, 12) : $appName;
        $themeColor = config('getfy.pwa_theme_color');
        $themeColor = ($themeColor !== null && $themeColor !== '') ? (string) $themeColor : (string) config('getfy.theme_primary', '#0ea5e9');

        $icons = PwaIcon::manifestIcons();

        $manifest = [
            'id' => '/',
            'name' => $appName,
            'short_name' => $shortName,
            'start_url' => '/dashboard',
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
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.auth' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'renewed' => ['sometimes', 'boolean'],
            'preferences' => ['sometimes', 'array'],
            'preferences.pix' => ['sometimes', 'boolean'],
            'preferences.boleto' => ['sometimes', 'boolean'],
            'preferences.card' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $keys = $validated['keys'];
        $keys['auth'] = $this->normalizeBase64KeyForPush((string) ($keys['auth'] ?? ''));
        $keys['p256dh'] = $this->normalizeBase64KeyForPush((string) ($keys['p256dh'] ?? ''));

        $existing = PanelPushSubscription::where('endpoint', $validated['endpoint'])->first();
        $preferences = PanelPushPreferences::normalize(
            $validated['preferences'] ?? $existing?->preferences
        );

        $subscription = PanelPushSubscription::updateOrCreate(
            [
                'endpoint' => $validated['endpoint'],
            ],
            [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'keys' => $keys,
                'user_agent' => $request->userAgent(),
                'preferences' => $preferences,
                'push_fail_count' => 0,
                'last_push_failed_at' => null,
            ]
        );

        $this->pruneStalePanelSubscriptions(
            $user->id,
            $user->tenant_id,
            $validated['endpoint'],
            $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'subscribed' => true,
            'subscription_id' => $subscription->id,
            'renewed' => (bool) ($validated['renewed'] ?? false),
            'preferences' => PanelPushPreferences::normalize($subscription->preferences),
            'updated_at' => $subscription->updated_at?->toISOString(),
        ]);
    }

    public function updatePushPreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.pix' => ['sometimes', 'boolean'],
            'preferences.boleto' => ['sometimes', 'boolean'],
            'preferences.card' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $preferences = PanelPushPreferences::normalize($validated['preferences']);

        PanelPushSubscription::where('user_id', $user->id)
            ->where('tenant_id', $user->tenant_id)
            ->update(['preferences' => $preferences]);

        return response()->json([
            'success' => true,
            'preferences' => $preferences,
        ]);
    }

    public function pushTest(Request $request, PanelPushService $push): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $result = $push->sendTestToUser($user);

        if ($result['subscriptions'] === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum dispositivo com notificações ativas. Ative as notificações neste navegador primeiro.',
                ...$result,
            ], 422);
        }

        return response()->json([
            'success' => $result['sent'] > 0,
            'message' => $result['sent'] > 0
                ? 'Notificação de teste enviada para '.$result['sent'].' '.($result['sent'] === 1 ? 'dispositivo' : 'dispositivos').'.'
                : 'Não foi possível entregar a notificação de teste. Tente reativar as notificações.',
            ...$result,
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

    /**
     * Remove inscrições antigas do mesmo navegador/dispositivo após renovação do push (deploy PWA).
     */
    private function pruneStalePanelSubscriptions(int $userId, ?int $tenantId, string $keepEndpoint, ?string $userAgent): void
    {
        $userAgent = is_string($userAgent) ? trim($userAgent) : '';
        if ($userAgent === '') {
            return;
        }

        PanelPushSubscription::query()
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('user_agent', $userAgent)
            ->where('endpoint', '!=', $keepEndpoint)
            ->delete();
    }
}
