<?php
    $path = request()->path();
    $isMemberArea = str_starts_with($path, 'm/') || request()->attributes->get('member_area_slug');
    $isCheckout = str_starts_with($path, 'c/') || str_starts_with($path, 'checkout') || str_starts_with($path, 'api-checkout');
    $skipPanelPwa = $isMemberArea || $isCheckout;
?>
<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        (function(){try{var s=localStorage.getItem('theme');var t=s||'dark';document.documentElement.classList.toggle('dark',t==='dark');}catch(_){}})();
    </script>
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo e(config('app.name', 'Getfy')); ?></title>
    <?php if (! ($skipPanelPwa)): ?>
    <link rel="icon" href="https://cdn.getfy.cloud/collapsed-logo.png" type="image/png">
    <link rel="manifest" href="<?php echo e(url('/manifest.json')); ?>">
    <meta name="theme-color" content="<?php echo e(config('getfy.theme_primary', '#0ea5e9')); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <?php if(is_file(public_path('icons/icon-192x192.png'))): ?>
    <link rel="apple-touch-icon" href="<?php echo e(url('/icons/icon-192x192.png')); ?>">
    <?php elseif(is_file(public_path('icons/icon-512x512.png'))): ?>
    <link rel="apple-touch-icon" href="<?php echo e(url('/icons/icon-512x512.png')); ?>">
    <?php endif; ?>
    <script>
        (function(){var e=null;window.addEventListener('beforeinstallprompt',function(t){t.preventDefault();e=t;window.__pwaInstallPrompt=e;},{capture:true});Object.defineProperty(window,'__pwaInstallPrompt',{get:function(){return e;},set:function(t){e=t;}});})();
    </script>
    <?php endif; ?>
    <?php if (!isset($__inertiaSsrDispatched)) { $__inertiaSsrDispatched = true; $__inertiaSsrResponse = app(\Inertia\Ssr\Gateway::class)->dispatch($page); }  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->head; } ?>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
</head>
<body class="antialiased">
    <?php
        $page = $page ?? [];
        $page['props'] = array_merge(
            [
                'auth' => ['user' => null],
                'flash' => ['success' => null, 'error' => null],
                'platform' => null,
            ],
            $page['props'] ?? []
        );
    ?>
    <div id="app" data-page="<?php echo e(json_encode($page)); ?>"></div>
</body>
</html>
<?php /**PATH C:\laragon\www\getfy-opensource\resources\views/app.blade.php ENDPATH**/ ?>