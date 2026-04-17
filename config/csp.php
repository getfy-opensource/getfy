<?php

return [
    /*
    | Origens HTTPS extra na diretiva CSP connect-src (PDF.js / apresentações).
    | Separadas por vírgula. Use se o URL público dos PDFs for outro domínio que não o de AWS_URL.
    */
    'extra_connect_src' => env('CSP_EXTRA_CONNECT_SRC', ''),
];
