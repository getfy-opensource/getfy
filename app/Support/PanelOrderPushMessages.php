<?php

namespace App\Support;

use App\Models\Order;

class PanelOrderPushMessages
{
    /** @return 'pix'|'boleto'|'card' */
    public static function categoryForOrder(Order $order): string
    {
        $meta = $order->metadata ?? [];
        $method = strtolower((string) ($meta['checkout_payment_method'] ?? ''));

        return match ($method) {
            'boleto' => 'boleto',
            'card', 'apple_pay', 'google_pay', 'paypal' => 'card',
            default => 'pix',
        };
    }

    /** @return array{title: string, body: string, url: string, category: 'pix'|'boleto'|'card'} */
    public static function forPixGenerated(Order $order): array
    {
        return [
            'title' => 'PIX gerado!',
            'body' => self::formatBody($order, 'Aguardando pagamento'),
            'url' => url('/vendas'),
            'category' => 'pix',
        ];
    }

    /** @return array{title: string, body: string, url: string, category: 'pix'|'boleto'|'card'} */
    public static function forBoletoGenerated(Order $order): array
    {
        return [
            'title' => 'Boleto gerado!',
            'body' => self::formatBody($order, 'Aguardando pagamento'),
            'url' => url('/vendas'),
            'category' => 'boleto',
        ];
    }

    /** @return array{title: string, body: string, url: string, category: 'pix'|'boleto'|'card'} */
    public static function forSaleApproved(Order $order): array
    {
        $methodLabel = $order->paymentMethodDisplayLabel();

        return [
            'title' => 'Venda aprovada!',
            'body' => self::formatBody($order, $methodLabel),
            'url' => url('/vendas?order=' . $order->id),
            'category' => self::categoryForOrder($order),
        ];
    }

    private static function formatBody(Order $order, string $suffix): string
    {
        $productName = $order->product?->name ?? 'Produto';
        $amount = self::formatAmount($order);

        return "{$productName} - {$amount}" . ($suffix !== '' ? " - {$suffix}" : '');
    }

    private static function formatAmount(Order $order): string
    {
        $currency = strtoupper((string) ($order->currency ?? 'BRL'));
        $amount = number_format((float) $order->amount, 2, ',', '.');

        return match ($currency) {
            'USD' => "US$ {$amount}",
            'EUR' => "€ {$amount}",
            'BRL', '' => "R$ {$amount}",
            default => "{$currency} {$amount}",
        };
    }
}
