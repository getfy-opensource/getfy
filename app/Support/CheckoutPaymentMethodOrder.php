<?php

namespace App\Support;

/**
 * Reordena métodos de pagamento do checkout conforme o país do visitante.
 */
class CheckoutPaymentMethodOrder
{
    /**
     * @param  array<int, array{id: string, label: string, gateway_slug?: string, gateway_name?: string}>  $methods
     * @return array<int, array{id: string, label: string, gateway_slug?: string, gateway_name?: string}>
     */
    public static function applyForCountry(array $methods, ?string $countryCode): array
    {
        if ($methods === []) {
            return [];
        }
        if ($countryCode === null || strtoupper($countryCode) === 'BR') {
            return $methods;
        }

        $priorityOrder = ['card', 'paypal', 'apple_pay', 'google_pay', 'boleto'];
        $pixTail = ['pix_auto', 'pix'];

        $byId = [];
        foreach ($methods as $m) {
            $id = $m['id'] ?? '';
            if ($id !== '') {
                $byId[$id] = $m;
            }
        }

        $out = [];
        foreach ($priorityOrder as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
                unset($byId[$id]);
            }
        }

        $remainingOriginalOrder = [];
        foreach ($methods as $m) {
            $id = $m['id'] ?? '';
            if ($id === '' || ! isset($byId[$id])) {
                continue;
            }
            if (in_array($id, $pixTail, true)) {
                continue;
            }
            $remainingOriginalOrder[] = $m;
            unset($byId[$id]);
        }
        $out = array_merge($out, $remainingOriginalOrder);

        foreach ($pixTail as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
                unset($byId[$id]);
            }
        }

        foreach ($byId as $m) {
            $out[] = $m;
        }

        return $out;
    }
}
