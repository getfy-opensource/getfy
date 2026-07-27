<?php

return [
    'default_gateway_fees' => [
        'pix' => ['percent' => 0.99, 'fixed_cents' => 0],
        'card' => ['percent' => 3.99, 'fixed_cents' => 39],
        'boleto' => ['percent' => 2.49, 'fixed_cents' => 0],
        'pix_auto' => ['percent' => 0.99, 'fixed_cents' => 0],
        'apple_pay' => ['percent' => 3.99, 'fixed_cents' => 39],
        'google_pay' => ['percent' => 3.99, 'fixed_cents' => 39],
        'paypal' => ['percent' => 3.99, 'fixed_cents' => 39],
    ],

    /** Defaults por gateway quando o tenant ainda não salvou taxas customizadas. */
    'gateway_default_fees' => [
        'cajupay' => [
            'pix' => ['percent' => 0, 'fixed_cents' => 99],
        ],
    ],

    'default_settlement_days' => [
        'pix' => 0,
        'card' => 30,
        'boleto' => 2,
        'pix_auto' => 0,
        'apple_pay' => 30,
        'google_pay' => 30,
        'paypal' => 30,
    ],

    'coproducer_invite_expires_days' => 14,

    'wallet_buckets' => [
        'pix' => ['pix', 'pix_auto'],
        'card' => ['card', 'apple_pay', 'google_pay', 'paypal'],
        'boleto' => ['boleto'],
    ],

    'min_payout_cents' => 100,

    'payout_rate_limit_per_minute' => 3,

    'partner_payout_requires_approval' => ['card', 'boleto'],

    'partner_payout_auto_execute' => ['pix'],
];
