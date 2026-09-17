<?php

/**
 * Content-Security-Policy (checkout, painel e pixels).
 *
 * Domínios extra via .env (vírgulas) para self-hosted / integrações customizadas:
 *   CSP_EXTRA_SCRIPT_SRC, CSP_EXTRA_CONNECT_SRC, CSP_EXTRA_FRAME_SRC
 *
 * Meta Conversions API Gateway / Signals Gateway usam hosts multi-label
 * (ex.: {id}.ecs.{region}.on.aws, {id}.{region}.run.app). CSP só aceita '*'
 * no label mais à esquerda — por isso listamos wildcards por região comum.
 * Se o pixel do cliente usar outra região, adicione o host em CSP_EXTRA_CONNECT_SRC.
 */

$scriptSources = [
    "'self'",
    "'unsafe-inline'",
    "'unsafe-eval'",
    // Pagamentos
    'https://js.stripe.com',
    'https://sdk.mercadopago.com',
    'https://http2.mlstatic.com',
    'https://*.mlstatic.com',
    'https://checkout.pagar.me',
    'https://cdn.cajupay.com.br',
    // Cartão Brasil (CajuPay form_mode Rinne) — SDK carrega https://pkgs.rinne.com.br/rinne-js
    'https://pkgs.rinne.com.br',
    'https://*.rinne.com.br',
    // Evervault (Rinne carrega js.evervault.com para campos PCI do cartão)
    'https://js.evervault.com',
    'https://*.evervault.com',
    // Apple Pay (SDK CajuPay / Payment Request)
    'https://applepay.cdn-apple.com',
    'https://www.paypal.com',
    'https://www.sandbox.paypal.com',
    'https://*.paypal.com',
    'https://*.paypalobjects.com',
    // Analytics / pixels
    'https://connect.facebook.net',
    'https://www.googletagmanager.com',
    'https://www.googleadservices.com',
    'https://googleads.g.doubleclick.net',
    'https://analytics.tiktok.com',
    'https://cdn.utmify.com.br',
    // Captcha
    'https://challenges.cloudflare.com',
    // YouTube IFrame API (player legado da área de membros)
    'https://www.youtube.com',
    'https://youtube.com',
    'https://s.ytimg.com',
    // Infra
    'https://static.cloudflareinsights.com',
];

/** Regiões AWS usadas pelo Meta Conversions API Gateway ({id}.ecs.{region}.on.aws). */
$metaCapiGatewayAwsRegions = [
    'us-west-1',
    'us-west-2',
    'us-east-1',
    'us-east-2',
    'eu-west-1',
    'eu-central-1',
    'sa-east-1',
    'ap-southeast-1',
    'ap-northeast-1',
];

/** Regiões GCP Cloud Run usadas pelo Meta Signals / CAPI Gateway ({id}.{region}.run.app). */
$metaCapiGatewayGcpRegions = [
    'us-central1',
    'us-east1',
    'us-east4',
    'us-west1',
    'europe-west1',
    'europe-west2',
    'europe-west3',
    'southamerica-east1',
    'asia-east1',
    'asia-northeast1',
];

$metaGatewayConnectSources = [];
foreach ($metaCapiGatewayAwsRegions as $region) {
    $metaGatewayConnectSources[] = 'https://*.ecs.'.$region.'.on.aws';
}
foreach ($metaCapiGatewayGcpRegions as $region) {
    $metaGatewayConnectSources[] = 'https://*.'.$region.'.run.app';
}

$connectSources = array_merge([
    "'self'",
    // Stripe
    'https://api.stripe.com',
    // PayPal
    'https://www.paypal.com',
    'https://www.sandbox.paypal.com',
    'https://api-m.paypal.com',
    'https://api-m.sandbox.paypal.com',
    'https://*.paypal.com',
    'https://*.paypalobjects.com',
    // Mercado Pago
    'https://api.mercadopago.com',
    'https://*.mercadopago.com',
    'https://*.mercadopago.com.br',
    'https://http2.mlstatic.com',
    'https://*.mlstatic.com',
    'https://api.mercadolibre.com',
    'https://www.mercadolibre.com',
    'https://*.mercadolibre.com',
    // Pagar.me
    'https://api.pagar.me',
    // CajuPay
    'https://api.cajupay.com.br',
    'https://*.cajupay.com.br',
    // Rinne (formulário seguro / 3DS do Cartão Brasil via CajuPay SDK)
    'https://pkgs.rinne.com.br',
    'https://*.rinne.com.br',
    'https://api.rinne.com.br',
    // Evervault (campos de cartão / keys / API usados pelo Rinne)
    'https://js.evervault.com',
    'https://keys.evervault.com',
    'https://api.evervault.com',
    'https://*.evervault.com',
    // Apple Pay (gateway + APIs Apple)
    'https://apple-pay-gateway.apple.com',
    'https://*.apple.com',
    // Efí — tokenização de cartão (payment-token-efi)
    'https://tokenizer.sejaefi.com.br',
    'https://cobrancas.api.efipay.com.br',
    'https://cobrancas-h.api.efipay.com.br',
    // Endereço
    'https://viacep.com.br',
    // Pixels / analytics (Meta Pixel + CAPI browser)
    'https://www.facebook.com',
    'https://connect.facebook.net',
    'https://graph.facebook.com',
    'https://*.facebook.com',
    'https://*.facebook.net',
    'https://www.googletagmanager.com',
    'https://www.googleadservices.com',
    'https://googleads.g.doubleclick.net',
    'https://stats.g.doubleclick.net',
    'https://www.google.com',
    'https://analytics.tiktok.com',
    'https://www.google-analytics.com',
    'https://*.google-analytics.com',
    'https://analytics.google.com',
    'https://region1.google-analytics.com',
    // Utmify
    'https://api.utmify.com.br',
    'https://cdn.utmify.com.br',
    // WebSocket / blobs (checkout SDKs)
    'wss:',
    'blob:',
], $metaGatewayConnectSources);

$frameSources = [
    "'self'",
    'https://js.stripe.com',
    'https://hooks.stripe.com',
    'https://m.stripe.network',
    'https://www.paypal.com',
    'https://www.sandbox.paypal.com',
    'https://*.paypal.com',
    'https://*.paypalobjects.com',
    'https://www.mercadopago.com',
    'https://*.mercadopago.com',
    'https://*.mercadopago.com.br',
    'https://www.mercadolibre.com',
    'https://*.mercadolibre.com',
    'https://www.youtube-nocookie.com',
    'https://youtube-nocookie.com',
    'https://www.youtube.com',
    'https://youtube.com',
    'https://challenges.cloudflare.com',
    'https://*.cajupay.com.br',
    'https://pkgs.rinne.com.br',
    'https://*.rinne.com.br',
    'https://js.evervault.com',
    'https://ui-components.evervault.com',
    'https://*.evervault.com',
    'https://checkout.pagar.me',
    // Meta Pixel (iframe / fbevents framing)
    'https://www.facebook.com',
    'https://*.facebook.com',
    'https://connect.facebook.net',
];

return [
    /*
    | Origens HTTPS extra (separadas por vírgula no .env).
    */
    'extra_script_src' => env('CSP_EXTRA_SCRIPT_SRC', ''),
    'extra_connect_src' => env('CSP_EXTRA_CONNECT_SRC', ''),
    'extra_frame_src' => env('CSP_EXTRA_FRAME_SRC', ''),

    /*
    | Incluir https://r2.getfy.cloud em connect-src (storage público Getfy Cloud).
    */
    'disable_getfy_r2_origin' => filter_var(env('CSP_DISABLE_GETFY_R2_ORIGIN', false), FILTER_VALIDATE_BOOL),

    'script_src' => $scriptSources,
    /** script-src-elem: browsers modernos aplicam esta diretiva a <script src>. */
    'script_src_elem' => $scriptSources,
    'style_src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
    'img_src' => ["'self'", 'data:', 'https:', 'blob:', 'https://www.googleadservices.com', 'https://googleads.g.doubleclick.net', 'https://www.google.com'],
    'font_src' => ["'self'", 'https://fonts.gstatic.com'],
    'connect_src' => $connectSources,
    'frame_src' => $frameSources,
    'media_src' => ["'self'", 'https:', 'blob:'],
    'worker_src' => ["'self'", 'blob:'],
];
