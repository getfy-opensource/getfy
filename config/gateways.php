<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Core gateways (slug => definition).
    | Plugins may register additional gateways via GatewayRegistry::register().
    |--------------------------------------------------------------------------
    */
    'gateways' => [
        'cajupay' => [
            'slug' => 'cajupay',
            'name' => 'CajuPay',
            'image' => 'images/gateways/cajupay.png',
            'methods' => ['pix', 'card', 'apple_pay', 'google_pay', 'pix_parcelado'],
            'scope' => 'international',
            'country' => 'br',
            'country_name' => 'Brasil / Global',
            'country_flag' => 'brasil.png',
            'countries' => [
                ['flag' => 'brasil.png', 'name' => 'Brasil'],
                ['flag' => 'global.png', 'name' => 'Global'],
            ],
            'signup_url' => 'https://cajupay.com.br',
            'driver' => \App\Gateways\CajuPay\CajuPayDriver::class,
            'credential_keys' => [
                [
                    'key' => 'public_key',
                    'label' => 'Chave pública',
                    'type' => 'text',
                    'hint' => 'Chave gpk_… do painel CajuPay. Ao salvar, o Getfy registra o webhook automaticamente na API.',
                ],
                [
                    'key' => 'secret_key',
                    'label' => 'Chave secreta',
                    'type' => 'password',
                    'hint' => 'Chave gsk_… do painel CajuPay. Necessária junto com a chave pública.',
                ],
                [
                    'key' => 'webhook_signing_secret',
                    'label' => 'Token do webhook (signing secret)',
                    'type' => 'password',
                    'optional' => true,
                    'advanced' => true,
                    'hint' => 'Preenchido automaticamente ao salvar as chaves. Só edite se rotacionou manualmente no painel CajuPay. Deixe em branco ao salvar para manter o token já gravado.',
                ],
            ],
        ],
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
                [
                    'key' => 'public_key',
                    'label' => 'Chave pública',
                    'type' => 'text',
                    'hint' => 'Chave pública do painel Spacepag (pk_live_… ou pk_test_…).',
                ],
                [
                    'key' => 'secret_key',
                    'label' => 'Chave privada',
                    'type' => 'password',
                    'hint' => 'Chave privada / secret (sk_live_… ou sk_test_…). O Getfy testa automaticamente: X-API-Key com sk, pk+sk (X-API-Key + X-API-Secret) ou só pk, conforme a API aceitar.',
                ],
                [
                    'key' => 'webhook_secret',
                    'label' => 'Secret do webhook (opcional)',
                    'type' => 'password',
                    'optional' => true,
                    'hint' => 'Ao criar o webhook na Spacepag apontando para a URL do Getfy, copie o secret retornado. Com ele o Getfy valida o header X-Webhook-Signature (HMAC SHA-256 do corpo). Cadastre na Spacepag a URL: https://SEU_DOMINIO/webhooks/gateways/spacepag . Deixe em branco ao salvar para manter o secret já gravado.',
                ],
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
        'pagarme' => [
            'slug' => 'pagarme',
            'name' => 'Pagar.me',
            'image' => 'images/gateways/pagarme.png',
            'methods' => ['pix', 'card', 'boleto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://pagar.me',
            'driver' => \App\Gateways\Pagarme\PagarmeDriver::class,
            'checkout_payload_keys' => ['public_key'],
            'credential_keys' => [
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
                ['key' => 'sandbox', 'label' => 'Sandbox', 'type' => 'boolean'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default redundancy order per method (when tenant has not configured).
    |--------------------------------------------------------------------------
    */
    'default_order' => [
        'pix' => ['cajupay', 'spacepag', 'efi', 'mercadopago', 'pagarme', 'pushinpay', 'asaas'],
        'card' => ['cajupay', 'efi', 'stripe', 'mercadopago', 'pagarme', 'asaas'],
        'boleto' => ['efi', 'mercadopago', 'pagarme', 'asaas'],
        'pix_auto' => ['efi', 'pushinpay'],
        'apple_pay' => ['cajupay'],
        'google_pay' => ['cajupay'],
        'pix_parcelado' => ['cajupay'],
        'crypto' => [],
    ],
];
