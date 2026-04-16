<?php

/**
 * Plugins instalados via ZIP/loja:
 * - GETFY_PLUGINS_USER_PATH definido: usa esse caminho absoluto.
 * - GETFY_DOCKER=true (Compose): `.docker/plugins-installed` — mesmo volume que `getfy_env` (.docker), separado de `storage`.
 * - Caso contrário: `storage/app/plugins-installed`.
 *
 * GETFY_PLUGINS_EXTRA_SCAN: pastas extras só de leitura, separadas por | (opcional).
 */
return [
    'user_install_path' => env('GETFY_PLUGINS_USER_PATH') ?: null,

    'docker_mode' => filter_var(env('GETFY_DOCKER', false), FILTER_VALIDATE_BOOLEAN),

    'extra_scan_paths' => array_values(array_filter(
        array_map('trim', explode('|', (string) env('GETFY_PLUGINS_EXTRA_SCAN', '')))
    )),
];
