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
            'methods' => ['pix', 'card', 'apple_pay', 'google_pay'],
            'scope' => 'international',
            'country' => 'br',
            'country_name' => 'Brasil / Global',
            'country_flag' => 'brasil.png',
            'countries' => [
                ['flag' => 'brasil.png', 'name' => 'Brasil'],
                ['flag' => 'global.png', 'name' => 'Global'],
            ],
            'signup_url' => 'https://cajupay.com.br/registro?ref=596d6c91fe',
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
        'asaas' => [
            'slug' => 'asaas',
            'name' => 'Asaas',
            'image' => 'images/gateways/asaas.png',
            'methods' => ['pix', 'card', 'boleto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://www.asaas.com/r/2617ea23-f001-4a8e-8413-2eb1a5f5145c',
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
    | Apenas CajuPay e Asaas habilitados.
    |--------------------------------------------------------------------------
    */
    'default_order' => [
        'pix' => ['cajupay', 'asaas'],
        'card' => ['cajupay', 'asaas'],
        'boleto' => ['asaas'],
        'pix_auto' => [],
        'apple_pay' => ['cajupay'],
        'google_pay' => ['cajupay'],
        'crypto' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Proteção contra plugins: impede alterações de gateways via plugin
    |--------------------------------------------------------------------------
    */
    'disable_plugin_gateway_registration' => true,
];
