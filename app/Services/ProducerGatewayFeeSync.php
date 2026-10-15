<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Order;
use App\Support\MoneyMinorUnits;

/**
 * Leva a taxa real do gateway (order.metadata.gateway_fee_cents / gateway_net_cents) para o lançamento do produtor.
 * Usado pela CajuPay (webhook) e pela Pagar.me (recebíveis).
 */
class ProducerGatewayFeeSync
{
    public function apply(Order $order): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! isset($meta['gateway_fee_cents']) || ! is_numeric($meta['gateway_fee_cents'])) {
            return;
        }

        $currency = $order->getCurrencyOrDefault();
        $fee = MoneyMinorUnits::fromMinorUnits((int) $meta['gateway_fee_cents'], $currency);
        $gross = round($order->lineItemsTotalAmount(), 2);
        $net = isset($meta['gateway_net_cents']) && is_numeric($meta['gateway_net_cents'])
            ? MoneyMinorUnits::fromMinorUnits((int) $meta['gateway_net_cents'], $currency)
            : max(0, round($gross - $fee, 2));

        $producerEntry = $order->commissionEntries()
            ->where('role', CommissionEntry::ROLE_PRODUTOR)
            ->first();

        if (! $producerEntry) {
            return;
        }

        $currentFee = round((float) $producerEntry->gateway_fee_amount, 2);
        if (abs($currentFee - $fee) < 0.01) {
            return;
        }

        $producerEntry->update([
            'gateway_fee_amount' => round($fee, 2),
            'net_amount' => round($net, 2),
        ]);
    }
}
