<?php

$versionFile = base_path('VERSION');
$version = trim((is_file($versionFile) ? file_get_contents($versionFile) : '') ?: '') ?: env('GETFY_VERSION', '1.0.0');

return [
    'installed' => is_file(base_path('.env')) && filter_var(env('APP_INSTALLED', false), FILTER_VALIDATE_BOOLEAN),
    'cloud_mode' => env('GETFY_CLOUD', false),
    'auto_migrate' => filter_var(env('APP_AUTO_MIGRATE', false), FILTER_VALIDATE_BOOLEAN),
    'cron_secret' => env('CRON_SECRET', null),
    'version' => $version,
    'update_repository_url' => env('GETFY_UPDATE_REPO', 'https://github.com/getfy-opensource/getfy.git'),
    'update_branch' => env('GETFY_UPDATE_BRANCH', 'main'),
    'updates_enabled' => env('GETFY_UPDATES_ENABLED', true),
    'php_path' => env('GETFY_PHP_PATH', null),
    'pwa' => [
        'vapid_public' => env('PWA_VAPID_PUBLIC', null),
        'vapid_private' => env('PWA_VAPID_PRIVATE', null),
    ],
    'app_name' => env('GETFY_APP_NAME', 'Getfy'),
    'theme_primary' => env('GETFY_THEME_PRIMARY', '#00cc00'),
    'app_logo' => env('GETFY_LOGO', 'https://cdn.getfy.cloud/logo-white.png'),
    'app_logo_dark' => env('GETFY_LOGO_DARK', 'https://cdn.getfy.cloud/logo-dark.png'),
    'app_logo_icon' => env('GETFY_LOGO_ICON', 'https://cdn.getfy.cloud/collapsed-logo.png'),
    'app_logo_icon_dark' => env('GETFY_LOGO_ICON_DARK', 'https://cdn.getfy.cloud/collapsed-logo.png'),
];
