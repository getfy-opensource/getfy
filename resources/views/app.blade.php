@php
    $path = request()->path();
    $isMemberArea = str_starts_with($path, 'm/') || request()->attributes->get('member_area_slug');
    $isCheckout = str_starts_with($path, 'c/') || str_starts_with($path, 'checkout') || str_starts_with($path, 'api-checkout');
    $skipPanelPwa = $isMemberArea || $isCheckout;
    $tenantId = auth()->user()?->tenant_id;
    $brandThemeColor = \App\Models\Setting::get('theme_primary', config('getfy.theme_primary', '#0ea5e9'), $tenantId);
    $brandFavicon = \App\Models\Setting::get('app_favicon', '', $tenantId);
    $brandIcon = \App\Models\Setting::get('app_logo_icon', 'https://cdn.getfy.cloud/collapsed-logo.png', $tenantId);
    $brandAppName = \App\Models\Setting::get('app_name', config('app.name', 'Getfy'), $tenantId);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        (function(){try{var s=localStorage.getItem('theme');var t=s||'dark';document.documentElement.classList.toggle('dark',t==='dark');}catch(_){}})();
    </script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $brandAppName }}</title>
    @unless($skipPanelPwa)
    <link rel="icon" href="{{ $brandFavicon ?: $brandIcon }}" type="image/png">
    <link rel="manifest" href="{{ url('/manifest.json') }}">
    <meta name="theme-color" content="{{ $brandThemeColor }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    @if(is_file(public_path('icons/icon-192x192.png')))
    <link rel="apple-touch-icon" href="{{ url('/icons/icon-192x192.png') }}">
    @elseif(is_file(public_path('icons/icon-512x512.png')))
    <link rel="apple-touch-icon" href="{{ url('/icons/icon-512x512.png') }}">
    @endif
    <script>
        (function(){var e=null;window.addEventListener('beforeinstallprompt',function(t){t.preventDefault();e=t;window.__pwaInstallPrompt=e;},{capture:true});Object.defineProperty(window,'__pwaInstallPrompt',{get:function(){return e;},set:function(t){e=t;}});})();
    </script>
    @endunless
    @inertiaHead
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">
    @php
        $page = $page ?? [];
        $page['props'] = array_merge(
            [
                'auth' => ['user' => null],
                'flash' => ['success' => null, 'error' => null],
                'platform' => null,
            ],
            $page['props'] ?? []
        );
    @endphp
    <div id="app" data-page="{{ json_encode($page) }}"></div>
</body>
</html>
