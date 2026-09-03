<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Política quando a reconfirmação via API do gateway falha (null) em
    | eventos destrutivos: cancelamento, rejeição e reembolso.
    |--------------------------------------------------------------------------
    |
    | accept = comportamento legado: aplica a mudança local mesmo sem conseguir
    |         consultar o gateway (ex.: API indisponível).
    | reject = fail-closed: não altera o pedido; o job pode retentar (útil quando
    |         o webhook de entrada não é confiável, ex. Mercado Pago sem assinatura).
    |
    */
    'reconfirm_fail_policy' => [
        'default' => env('WEBHOOK_RECONFIRM_FAIL_POLICY', 'accept'),

        'mercadopago' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_MERCADOPAGO', 'reject'),
        'pushinpay' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_PUSHINPAY'),
        'asaas' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_ASAAS'),
        'efi' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_EFI'),
        'stripe' => env('WEBHOOK_RECONFIRM_FAIL_POLICY_STRIPE'),
    ],
];
