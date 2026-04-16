<?php

/**
 * Plugins instalados via ZIP/loja vão para `user_install_path` (padrão: /plugins-installed na raiz do projeto),
 * fora da pasta `plugins/` versionada no Git — assim atualizações do código não apagam white-label etc.
 *
 * GETFY_PLUGINS_USER_PATH: caminho absoluto opcional (ex.: /var/www/getfy/shared/plugins em deploy com releases).
 * GETFY_PLUGINS_EXTRA_SCAN: pastas extras para descobrir plugins, separadas por | (opcional).
 */
return [
    'user_install_path' => env('GETFY_PLUGINS_USER_PATH') ?: null,

    'extra_scan_paths' => array_values(array_filter(
        array_map('trim', explode('|', (string) env('GETFY_PLUGINS_EXTRA_SCAN', '')))
    )),
];
