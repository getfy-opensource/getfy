<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PIX reconcile poll tiers (by order age)
    |--------------------------------------------------------------------------
    |
    | Younger orders are checked more often. After the last tier, use
    | pix_stale_interval_minutes so old pending PIX keep reconciling without
    | spamming the gateway API.
    |
    */
    'pix_tiers' => [
        ['max_age_minutes' => 10, 'interval_minutes' => 1],
        ['max_age_minutes' => 30, 'interval_minutes' => 5],
        ['max_age_minutes' => 120, 'interval_minutes' => 15],
    ],

    /*
    | Interval (minutes) for PIX older than the last pix_tiers max_age.
    */
    'pix_stale_interval_minutes' => 60,

    /*
    | Auto-cancel unpaid PIX after this many minutes (reconcile_pix_expired).
    | 0 = never cancel by age; keep status pending for dashboard/conversion.
    | Gateway webhooks that report cancelled still cancel the order.
    */
    'pix_max_age_minutes' => 0,

    'legacy_interval_minutes' => 2,
];
