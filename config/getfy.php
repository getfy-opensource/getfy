<?php

use App\Support\VapidEnvKeys;

$versionFile = base_path('VERSION');
$version = trim((is_file($versionFile) ? file_get_contents($versionFile) : '') ?: '') ?: env('GETFY_VERSION', '1.0.0');

$cloudModeEnv = filter_var(env('GETFY_CLOUD', false), FILTER_VALIDATE_BOOLEAN);
$cloudModeFolder = is_dir(base_path('cloud'));

return [
    'installed' => is_file(base_path('.env')) && filter_var(env('APP_INSTALLED', false), FILTER_VALIDATE_BOOLEAN),
    'cloud_mode' => $cloudModeEnv || $cloudModeFolder,
    'cloud' => [
        'orch_api_base_url' => rtrim((string) env('ORCH_API_BASE_URL', 'https://orch.getfy.cloud'), '/'),
        'billing_cache_minutes' => (int) env('GETFY_CLOUD_BILLING_CACHE_MINUTES', 10),
        'billing_renew_window_days' => (int) env('GETFY_CLOUD_BILLING_RENEW_WINDOW_DAYS', 7),
    ],
    'auto_migrate' => filter_var(env('APP_AUTO_MIGRATE', false), FILTER_VALIDATE_BOOLEAN),
    // Financeiro Pagar.me: transferir, antecipar e mudar a transferência automática pelo admin. Leitura funciona sem a flag.
    'pagarme_finance_write' => filter_var(env('GETFY_PAGARME_FINANCE_WRITE', false), FILTER_VALIDATE_BOOLEAN),
    'cron_secret' => env('CRON_SECRET', null),

    /*
    | Webhooks de integração (Integrações > Webhooks): envio HTTP para URLs cadastradas.
    | Por padrão, pedido pendente e pedido pago disparam na hora (sem fila), para evitar atraso
    | quando o worker está sobrecarregado. Demais eventos seguem fila/heartbeat (ver docs).
    */
    'webhooks' => [
        'sync_critical_payment_events' => filter_var(
            env('GETFY_WEBHOOKS_SYNC_CRITICAL_PAYMENT', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        /** Se true, todos os webhooks de integração rodam síncronos (pode alongar requests). */
        'dispatch_all_sync' => filter_var(env('GETFY_WEBHOOKS_DISPATCH_ALL_SYNC', false), FILTER_VALIDATE_BOOLEAN),
        /**
         * Webhooks de integração sempre enviam customer em texto claro; esta flag só afeta sanitização legada.
         */
        'include_plain_customer_pii' => filter_var(
            env('GETFY_WEBHOOKS_PLAIN_CUSTOMER_PII', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        /** false por padrão: não enviar email_hash, phone_hash, etc. (use true só para Meta CAPI). */
        'include_customer_hashes' => filter_var(
            env('GETFY_WEBHOOKS_CUSTOMER_HASHES', false),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    /*
    | Meta CAPI, Utmify paid, etc.: após a resposta HTTP quando fila é sync/database
    | ou GETFY_INTEGRATIONS_DISPATCH_AFTER_RESPONSE=true (default true).
    */
    'integrations' => [
        'dispatch_after_response' => filter_var(
            env('GETFY_INTEGRATIONS_DISPATCH_AFTER_RESPONSE', true),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],
    'version' => $version,
    'update_repository_url' => env('GETFY_UPDATE_REPO', 'https://github.com/getfy-opensource/getfy.git'),
    'update_branch' => env('GETFY_UPDATE_BRANCH', 'main'),
    'updates_enabled' => env('GETFY_UPDATES_ENABLED', true),
    'php_path' => env('GETFY_PHP_PATH', null),
    'pwa' => [
        'vapid_public' => VapidEnvKeys::normalize(env('PWA_VAPID_PUBLIC')),
        'vapid_private' => VapidEnvKeys::normalize(env('PWA_VAPID_PRIVATE')),
    ],
    'integrax' => [
        'default_from' => env('INTEGRAX_DEFAULT_FROM', ''),
    ],
    /**
     * Marketplace público (link aberto no painel → Loja de plugins).
     * @see https://getfy.org/plugins
     */
    'plugin_store_url' => rtrim((string) env('PLUGIN_STORE_URL', env('GETFY_PLUGIN_STORE_URL', 'https://getfy.org/plugins')), '/'),

    /**
     * Origem da API da loja (listagem, download, licença). Sem o path /plugins.
     */
    'plugin_store_api_url' => rtrim((string) env('PLUGIN_STORE_API_URL', 'https://getfy.org'), '/'),

    'app_name' => 'Getfy',
    'theme_primary' => '#74d909',
    'app_logo' => 'https://cdn.getfy.cloud/logo-white-v3.png',
    'app_logo_dark' => 'https://cdn.getfy.cloud/logo-dark-v3.png',
    'app_logo_icon' => 'https://cdn.getfy.cloud/collapsed-logo-v3.png',
    'app_logo_icon_dark' => 'https://cdn.getfy.cloud/collapsed-logo-v2.png',

    /** White Label plugin (null = default / não aplicado) */
    'login_hero_image' => null,
    'favicon_url' => '/brand/favicon.png',
    'pwa_theme_color' => null,
    /** Ícone do PWA (painel). Usado no manifest e no “Adicionar à tela inicial”. */
    'pwa_icon' => '/icons/icon.png',
    'pwa_icon_192' => '/icons/icon.png',
    'pwa_icon_512' => '/icons/icon.png',
];
