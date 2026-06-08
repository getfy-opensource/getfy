<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CONFIGURAÇÃO EM CACHE: Gateways autorizados e bloqueados
    | Gerado automaticamente. Não edite manualmente.
    | Data: 2026-06-08
    |--------------------------------------------------------------------------
    */
    
    'allowed_gateways' => [
        'cajupay',
        'asaas',
    ],
    
    'blocked_gateways' => [
        'spacepag',
        'sapcepag',
        'efi',
        'stripe',
        'mercadopago',
        'pushinpay',
        'pagarme',
    ],
    
    'gateway_configs' => [
        'cajupay' => [
            'signup_url' => 'https://cajupay.com.br/registro?ref=596d6c91fe',
        ],
        'asaas' => [
            'signup_url' => 'https://www.asaas.com/r/2617ea23-f001-4a8e-8413-2eb1a5f5145c',
        ],
    ],
    
    'cache_version' => '1.0',
    'cached_at' => time(),
];
