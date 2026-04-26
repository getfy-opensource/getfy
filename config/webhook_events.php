<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Webhook Events Catalog
    | Event class => Human-readable label (for UI)
    |--------------------------------------------------------------------------
    */
    'events' => [
        // Pagamento
        \App\Events\OrderPending::class => 'Pedido pendente',
        \App\Events\OrderCompleted::class => 'Pedido pago',
        \App\Events\AccessDeliveryReady::class => 'Envio de acesso (pós-aprovação)',
        \App\Events\OrderRejected::class => 'Pagamento recusado',
        \App\Events\OrderCancelled::class => 'Pedido cancelado',
        \App\Events\OrderRefunded::class => 'Reembolso',
        \App\Events\PixGenerated::class => 'Pix gerado',
        \App\Events\BoletoGenerated::class => 'Boleto gerado',
        \App\Events\CartAbandoned::class => 'Carrinho abandonado',

        // Assinatura
        \App\Events\SubscriptionCreated::class => 'Assinatura criada',
        \App\Events\SubscriptionRenewed::class => 'Assinatura renovada',
        \App\Events\SubscriptionCancelled::class => 'Assinatura cancelada',
        \App\Events\SubscriptionPastDue::class => 'Assinatura em atraso',
    ],

    /*
    |--------------------------------------------------------------------------
    | Event slugs (enviados no payload: event = slug, ex: pedido_pago)
    |--------------------------------------------------------------------------
    */
    'event_slugs' => [
        \App\Events\OrderPending::class => 'pedido_pendente',
        \App\Events\OrderCompleted::class => 'pedido_pago',
        \App\Events\AccessDeliveryReady::class => 'envio_acesso',
        \App\Events\OrderRejected::class => 'pagamento_recusado',
        \App\Events\OrderCancelled::class => 'pedido_cancelado',
        \App\Events\OrderRefunded::class => 'reembolso',
        \App\Events\PixGenerated::class => 'pix_gerado',
        \App\Events\BoletoGenerated::class => 'boleto_gerado',
        \App\Events\CartAbandoned::class => 'carrinho_abandonado',
        \App\Events\SubscriptionCreated::class => 'assinatura_criada',
        \App\Events\SubscriptionRenewed::class => 'assinatura_renovada',
        \App\Events\SubscriptionCancelled::class => 'assinatura_cancelada',
        \App\Events\SubscriptionPastDue::class => 'assinatura_em_atraso',
    ],

    /*
    |--------------------------------------------------------------------------
    | Test event (manual trigger from UI)
    |--------------------------------------------------------------------------
    */
    'test_event' => 'webhook.test',
    'test_event_label' => 'Evento de teste (manual)',
];
