<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Member Builder — {{ $produto['name'] ?? config('app.name') }}</title>
    @php
        $viteDevHot = public_path('hot');
        $vueBridgeBuilt = public_path('build/getfy-plugin-vue.mjs');
        $vueBridgeImport = (! file_exists($viteDevHot) && file_exists($vueBridgeBuilt))
            ? asset('build/getfy-plugin-vue.mjs')
            : \Illuminate\Support\Facades\Vite::asset('resources/js/plugins/getfyPluginVueBridge.js');
    @endphp
    <script type="importmap">
        {"scopes":{"/plugins/":{"vue":@json($vueBridgeImport)}}}
    </script>
    @vite(['resources/css/app.css', 'resources/js/member-builder.js'])
</head>
<body class="bg-zinc-100 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <div id="member-builder-app"></div>
    @php
        $memberBuilderData = [
            'produto' => $produto,
            'tenant_products' => $tenant_products ?? [],
            'app_url' => $app_url ?? rtrim(config('app.url'), '/'),
            'dns_target_host' => $dns_target_host ?? null,
            'dns_target_ip' => $dns_target_ip ?? null,
            'upload_limits' => $upload_limits ?? [
                'image_max_mb' => 10,
                'badge_max_mb' => 5,
                'pdf_max_mb' => 50,
            ],
            'plugin_member_builder_tabs' => $plugin_member_builder_tabs ?? [],
            'plugin_ui' => $plugin_ui ?? ['plugins' => []],
            'vue_bridge_url' => $vueBridgeImport,
        ];
    @endphp
    <script>
        window.__MEMBER_BUILDER__ = @json($memberBuilderData);
    </script>
</body>
</html>
