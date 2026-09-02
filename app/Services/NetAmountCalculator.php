<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\GatewayFeeSetting;
use App\Models\Order;
use App\Support\MoneyMinorUnits;

class NetAmountCalculator
{
    public const FEE_SOURCE_GATEWAY_WEBHOOK = 'gateway_webhook';

    public const FEE_SOURCE_COMMISSION_ENTRY = 'commission_entry';

    public const FEE_SOURCE_ESTIMATED = 'estimated';

    /**
     * @return array{gross: float, fee: float, net: float, fee_source: string}
     */
    public function forOrder(Order $order): array
    {
        $gross = round($order->lineItemsTotalAmount(), 2);
        $currency = $order->getCurrencyOrDefault();
        $meta = is_array($order->metadata) ? $order->metadata : [];

        $feeCents = $meta['gateway_fee_cents'] ?? null;
        $netCents = $meta['gateway_net_cents'] ?? null;
        if (is_numeric($feeCents) && (int) $feeCents >= 0) {
            $fee = MoneyMinorUnits::fromMinorUnits((int) $feeCents, $currency);
            if (is_numeric($netCents) && (int) $netCents >= 0) {
                $net = MoneyMinorUnits::fromMinorUnits((int) $netCents, $currency);
            } else {
                $net = max(0, round($gross - $fee, 2));
            }

            return [
                'gross' => $gross,
                'fee' => round($fee, 2),
                'net' => round($net, 2),
                'fee_source' => (string) ($meta['gateway_fee_source'] ?? self::FEE_SOURCE_GATEWAY_WEBHOOK),
            ];
        }

        $order->loadMissing('commissionEntries');
        $producerEntry = $order->commissionEntries
            ->firstWhere('role', CommissionEntry::ROLE_PRODUTOR);
        if ($producerEntry && (float) $producerEntry->gateway_fee_amount > 0) {
            $fee = round((float) $producerEntry->gateway_fee_amount, 2);
            $net = $producerEntry->net_amount !== null
                ? round((float) $producerEntry->net_amount, 2)
                : max(0, round($gross - $fee, 2));

            return [
                'gross' => $gross,
                'fee' => $fee,
                'net' => $net,
                'fee_source' => self::FEE_SOURCE_COMMISSION_ENTRY,
            ];
        }

        $method = $order->checkoutPaymentMethod();
        $gateway = strtolower((string) ($order->gateway ?? ''));
        $tenantId = (int) $order->tenant_id;
        $fee = $this->estimateFee($tenantId, $gateway, $method, $gross);
        $net = max(0, round($gross - $fee, 2));

        return [
            'gross' => $gross,
            'fee' => $fee,
            'net' => $net,
            'fee_source' => self::FEE_SOURCE_ESTIMATED,
        ];
    }

    public function estimateFee(int $tenantId, string $gatewaySlug, string $method, float $gross): float
    {
        $setting = null;
        if ($gatewaySlug !== '') {
            $setting = GatewayFeeSetting::forTenant($tenantId)
                ->where('gateway_slug', $gatewaySlug)
                ->where('method', $method)
                ->first();
        }

        if ($setting) {
            $percent = (float) $setting->percent;
            $fixed = ((int) $setting->fixed_cents) / 100;

            return round(($gross * $percent / 100) + $fixed, 2);
        }

        $cfg = GatewayFeeSetting::defaultsFor($gatewaySlug, $method);
        $percent = (float) ($cfg['percent'] ?? 0);
        $fixed = ((int) ($cfg['fixed_cents'] ?? 0)) / 100;

        return round(($gross * $percent / 100) + $fixed, 2);
    }
}
