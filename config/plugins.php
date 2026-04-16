<?php

/**
 * Plugins instalados via ZIP/loja vão para `user_install_path` ou, se vazio, para storage/app/plugins-installed
 * (costuma persistir quando `storage/` é partilhado entre releases). A pasta `plugins/` no repositório continua
 * só para exemplos versionados (ex.: example-gateway).
 *
 * GETFY_PLUGINS_USER_PATH: caminho absoluto recomendado em produção se apagarem o projeto inteiro ou `storage/`
 * (ex.: /var/www/getfy/shared/plugins).
 * GETFY_PLUGINS_EXTRA_SCAN: pastas extras só de leitura, separadas por | (opcional).
 */
return [
    'user_install_path' => env('GETFY_PLUGINS_USER_PATH') ?: null,

    'extra_scan_paths' => array_values(array_filter(
        array_map('trim', explode('|', (string) env('GETFY_PLUGINS_EXTRA_SCAN', '')))
    )),
];
