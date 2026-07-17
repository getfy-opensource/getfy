<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Http\Request;

class MemberAreaLoginPageProps
{
    /**
     * @return array<string, mixed>
     */
    public static function productArray(Product $product, Request $request, ?string $slug = null): array
    {
        $config = $product->member_area_config;
        $loginConfig = $config['login'] ?? [];
        $slug = $slug ?? $request->route('slug') ?? $request->attributes->get('member_area_slug');
        $template = $loginConfig['template'] ?? 'v1';

        return [
            'name' => $product->name,
            'logo_light' => $loginConfig['logo'] ?? ($config['logos']['logo_light'] ?? ''),
            'logo_dark' => $loginConfig['logo'] ?? ($config['logos']['logo_dark'] ?? ($config['logos']['logo_light'] ?? '')),
            'title' => $loginConfig['title'] ?? 'Área de Membros',
            'subtitle' => $loginConfig['subtitle'] ?? 'Entre com seu e-mail e senha',
            'background_image' => $loginConfig['background_image'] ?? '',
            'background_color' => $loginConfig['background_color'] ?? '#18181b',
            'primary_color' => $loginConfig['primary_color'] ?? '#0ea5e9',
            'background_overlay_opacity' => isset($loginConfig['background_overlay_opacity'])
                ? (float) $loginConfig['background_overlay_opacity']
                : 50,
            'template' => in_array($template, ['v1', 'v2'], true) ? $template : 'v1',
            'login_without_password' => (bool) ($loginConfig['login_without_password'] ?? false),
            'login_without_password_url' => ! empty($loginConfig['login_without_password']) && $slug
                ? ($request->route('slug') !== null ? url('/m/'.$slug.'/login-without-password') : url('/login-without-password'))
                : null,
        ];
    }
}
