<?php

return [
    /*
    | Origens HTTPS extra na diretiva CSP connect-src (PDF.js / apresentações).
    | Separadas por vírgula. Use se o URL público dos PDFs for outro domínio que não o de AWS_URL.
    */
    'extra_connect_src' => env('CSP_EXTRA_CONNECT_SRC', ''),

    /*
    | Incluir https://r2.getfy.cloud em connect-src (storage público Getfy Cloud).
    | Defina true em instalações self-hosted que não usem esse domínio.
    */
    'disable_getfy_r2_origin' => filter_var(env('CSP_DISABLE_GETFY_R2_ORIGIN', false), FILTER_VALIDATE_BOOL),
];
