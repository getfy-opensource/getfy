<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Core gateways (slug => definition).
    | Plugins may register additional gateways via GatewayRegistry::register().
    |--------------------------------------------------------------------------
    */
    'gateways' => [
        'spacepag' => [
            'slug' => 'spacepag',
            'name' => 'Spacepag',
            'image' => 'images/gateways/spacepag2.png',
            'methods' => ['pix'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://hub.spacepag.com.br/auth/jwt/sign-up?ref=4a5d0212320748719ee818cffdb93248',
            'driver' => \App\Gateways\Spacepag\SpacepagDriver::class,
            'credential_keys' => [
                ['key' => 'public_key', 'label' => 'Chave pública', 'type' => 'text'],
                ['key' => 'secret_key', 'label' => 'Chave secreta', 'type' => 'password'],
                ['key' => 'force_ipv4', 'label' => 'Forçar IPv4 (se houver timeout no servidor)', 'type' => 'boolean'],
                ['key' => 'timeout', 'label' => 'Timeout (segundos)', 'type' => 'text'],
                ['key' => 'connect_timeout', 'label' => 'Timeout de conexão (segundos)', 'type' => 'text'],
                ['key' => 'base_url', 'label' => 'Base URL (opcional)', 'type' => 'text'],
            ],
        ],
        'efi' => [
            'slug' => 'efi',
            'name' => 'Efí',
            'image' => 'images/gateways/efi.png',
            'methods' => ['pix', 'card', 'boleto', 'pix_auto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://sejaefi.com.br',
            'driver' => \App\Gateways\Efi\EfiDriver::class,
            'certificate_key' => 'certificate',
            'credential_keys' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
                ['key' => 'pix_key', 'label' => 'Chave PIX (E‑mail, CPF, CNPJ ou aleatória)', 'type' => 'text'],
                ['key' => 'payee_code', 'label' => 'Identificador de conta (payee_code) — para cartão', 'type' => 'text'],
                ['key' => 'sandbox', 'label' => 'Usar ambiente de homologação (sandbox)', 'type' => 'boolean'],
                ['key' => 'certificate', 'label' => 'Certificado P12', 'type' => 'file'],
            ],
        ],
        'stripe' => [
            'slug' => 'stripe',
            'name' => 'Stripe',
            'image' => 'images/gateways/stripe.png',
            'methods' => ['card'],
            'scope' => 'international',
            'country_flag' => 'global.png',
            'country_name' => 'Global',
            'signup_url' => 'https://dashboard.stripe.com/register',
            'driver' => \App\Gateways\Stripe\StripeDriver::class,
            'credential_keys' => [
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'publishable_key', 'label' => 'Publishable Key', 'type' => 'text'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret (whsec_...)', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar ambiente de teste', 'type' => 'boolean'],
                ['key' => 'link_enabled', 'label' => 'Habilitar Stripe Link no checkout', 'type' => 'boolean'],
            ],
        ],
        'mercadopago' => [
            'slug' => 'mercadopago',
            'name' => 'Mercado Pago',
            'image' => 'images/gateways/mercado-pago.webp',
            'methods' => ['pix', 'card', 'boleto'],
            'scope' => 'international',
            'country' => 'br',
            'country_name' => 'Brasil, Argentina, Chile, Colômbia, México, Peru, Uruguai',
            'country_flag' => 'brasil.png',
            'countries' => [
                ['flag' => 'brasil.png', 'name' => 'Brasil'],
                ['flag' => 'argentina.png', 'name' => 'Argentina'],
                ['flag' => 'chile.png', 'name' => 'Chile'],
                ['flag' => 'colombia.png', 'name' => 'Colômbia'],
                ['flag' => 'mexico.png', 'name' => 'México'],
                ['flag' => 'peru.png', 'name' => 'Peru'],
                ['flag' => 'uruguay.png', 'name' => 'Uruguai'],
            ],
            'signup_url' => 'https://www.mercadopago.com.br/developers',
            'driver' => \App\Gateways\MercadoPago\MercadoPagoDriver::class,
            'credential_keys' => [
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
                ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar sandbox (credenciais de teste)', 'type' => 'boolean'],
            ],
        ],
        'pushinpay' => [
            'slug' => 'pushinpay',
            'name' => 'Pushin Pay',
            'image' => 'images/gateways/pushinpay.png',
            'methods' => ['pix', 'pix_auto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://app.pushinpay.com.br/register',
            'driver' => \App\Gateways\PushinPay\PushinPayDriver::class,
            'credential_keys' => [
                ['key' => 'api_token', 'label' => 'API Token', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar ambiente de homologação (sandbox)', 'type' => 'boolean'],
            ],
        ],
        'asaas' => [
            'slug' => 'asaas',
            'name' => 'Asaas',
            'image' => 'images/gateways/asaas.png',
            'methods' => ['pix', 'card', 'boleto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://www.asaas.com',
            'driver' => \App\Gateways\Asaas\AsaasDriver::class,
            'credential_keys' => [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar ambiente de homologação (sandbox)', 'type' => 'boolean'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default redundancy order per method (when tenant has not configured).
    |--------------------------------------------------------------------------
    */
    'default_order' => [
        'pix' => ['spacepag', 'efi', 'mercadopago', 'pushinpay', 'asaas'],
        'card' => ['efi', 'stripe', 'mercadopago', 'asaas'],
        'boleto' => ['efi', 'mercadopago', 'asaas'],
        'pix_auto' => ['efi', 'pushinpay'],
        'crypto' => [],
    ],
];
