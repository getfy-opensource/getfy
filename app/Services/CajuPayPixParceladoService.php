<?php

namespace App\Services;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Support\MoneyMinorUnits;

class CajuPayPixParceladoService
{
    public const MIN_AMOUNT_CENTS = 5000;

    /**
     * @return array<string, mixed>|null
     */
    public function credentialsForTenant(?int $tenantId): ?array
    {
        $credential = GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            return null;
        }

        return $credential->getDecryptedCredentials();
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function enrollmentStatus(array $credentials): array
    {
        $driver = $this->driver();

        return $driver->getPixParceladoEnrollment($credentials);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function isEnrolled(array $credentials): bool
    {
        $cached = strtolower(trim((string) ($credentials['pix_parcelado_enrollment_status'] ?? '')));
        if ($cached === 'active') {
            return true;
        }
        if (in_array($cached, ['pending', 'suspended'], true)) {
            return false;
        }

        try {
            $data = $this->enrollmentStatus($credentials);

            return strtolower(trim((string) ($data['status'] ?? ''))) === 'active';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function resolvePayAccountId(array $credentials): ?string
    {
        return $this->driver()->resolvePayAccountId($credentials);
    }

    public function resolvePayAccountIdForTenant(?int $tenantId): ?string
    {
        $credentials = $this->credentialsForTenant($tenantId);
        if (! $credentials) {
            return null;
        }

        $payAccountId = $this->resolvePayAccountId($credentials);
        if ($payAccountId !== null && $payAccountId !== '') {
            $this->rememberPayAccountId($tenantId, $payAccountId);
        }

        return $payAccountId;
    }

    public function rememberPayAccountId(?int $tenantId, string $payAccountId): void
    {
        $payAccountId = trim($payAccountId);
        if ($tenantId === null || $payAccountId === '') {
            return;
        }

        $credential = GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            return;
        }

        $credentials = $credential->getDecryptedCredentials();
        if (trim((string) ($credentials['pay_account_id'] ?? '')) === $payAccountId) {
            return;
        }

        $credentials['pay_account_id'] = $payAccountId;
        $credential->setEncryptedCredentials($credentials);
        $credential->save();
    }

    public function rememberEnrollmentStatus(?int $tenantId, string $status): void
    {
        $status = strtolower(trim($status));
        if ($tenantId === null || $status === '') {
            return;
        }

        $credential = GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            return;
        }

        $credentials = $credential->getDecryptedCredentials();
        if (strtolower(trim((string) ($credentials['pix_parcelado_enrollment_status'] ?? ''))) === $status) {
            return;
        }

        $credentials['pix_parcelado_enrollment_status'] = $status;
        $credential->setEncryptedCredentials($credentials);
        $credential->save();
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function platformRules(array $credentials): array
    {
        try {
            return $this->driver()->getPixParceladoPlatformRules($credentials);
        } catch (\Throwable) {
            return [
                'max_down_payment_bps' => 6000,
                'bands' => [],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $platformRules
     */
    public function maxInstallmentsForAmount(int $totalCents, array $platformRules = []): int
    {
        $bands = $platformRules['bands'] ?? null;
        if (is_array($bands) && $bands !== []) {
            foreach ($bands as $band) {
                if (! is_array($band)) {
                    continue;
                }
                $min = (int) ($band['min_total_cents'] ?? $band['min_cents'] ?? 0);
                $max = (int) ($band['max_total_cents'] ?? $band['max_cents'] ?? PHP_INT_MAX);
                $maxInst = (int) ($band['max_installments'] ?? $band['max_installment_count'] ?? 0);
                if ($totalCents >= $min && $totalCents <= $max && $maxInst > 0) {
                    return $maxInst;
                }
            }
        }

        return match (true) {
            $totalCents >= 1_000_000 => 24,
            $totalCents >= 150_100 => 24,
            $totalCents >= 999_00 => 15,
            $totalCents >= 300_00 => 6,
            $totalCents >= 50_00 => 3,
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $productRules
     * @param  array<string, mixed>  $platformRules
     * @return array<string, mixed>
     */
    public function mergeProductRulesWithPlatform(int $totalCents, array $productRules, array $platformRules): array
    {
        $platformMaxInstallments = $this->maxInstallmentsForAmount($totalCents, $platformRules);
        $productMax = isset($productRules['max_installments']) && $productRules['max_installments'] !== null
            ? (int) $productRules['max_installments']
            : null;
        $effectiveMax = $platformMaxInstallments;
        if ($productMax !== null && $productMax > 0) {
            $effectiveMax = min($platformMaxInstallments, $productMax);
        }

        $platformDownCap = (int) ($platformRules['max_down_payment_bps'] ?? 6000);
        $productDownCap = isset($productRules['max_down_payment_bps']) && $productRules['max_down_payment_bps'] !== null
            ? (int) $productRules['max_down_payment_bps']
            : null;
        $effectiveDownCap = $productDownCap !== null && $productDownCap > 0
            ? min($platformDownCap, $productDownCap)
            : $platformDownCap;

        return [
            'max_installments' => max(0, $effectiveMax),
            'platform_max_installments' => $platformMaxInstallments,
            'max_down_payment_bps' => $effectiveDownCap,
            'platform_max_down_payment_bps' => $platformDownCap,
            'down_payment_cents' => $productRules['down_payment_cents'] ?? null,
            'min_down_payment_bps' => $productRules['min_down_payment_bps'] ?? null,
            'early_payment_discount_bps' => (int) ($productRules['early_payment_discount_bps'] ?? 0),
            'payoff_discount_bps' => (int) ($productRules['payoff_discount_bps'] ?? 0),
            'overdue_payoff_discount_bps' => (int) ($productRules['overdue_payoff_discount_bps'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $platformRules
     * @return array<string, string>
     */
    public function validateProductRules(array $rules, array $platformRules, float $priceBrl): array
    {
        $errors = [];
        $totalCents = MoneyMinorUnits::toMinorUnits($priceBrl, 'BRL');
        if ($totalCents < self::MIN_AMOUNT_CENTS) {
            $errors['price'] = 'PIX Parcelado exige valor mínimo de R$ 50,00.';
        }

        $platformCap = (int) ($platformRules['max_down_payment_bps'] ?? 6000);
        if (isset($rules['max_down_payment_bps']) && $rules['max_down_payment_bps'] !== null && $rules['max_down_payment_bps'] !== '') {
            $bps = (int) $rules['max_down_payment_bps'];
            if ($bps < 0 || $bps > $platformCap) {
                $errors['max_down_payment_bps'] = 'Entrada máxima não pode exceder '.($platformCap / 100).'% da plataforma.';
            }
        }

        if (isset($rules['min_down_payment_bps']) && $rules['min_down_payment_bps'] !== null && $rules['min_down_payment_bps'] !== ''
            && isset($rules['max_down_payment_bps']) && $rules['max_down_payment_bps'] !== null && $rules['max_down_payment_bps'] !== '') {
            if ((int) $rules['min_down_payment_bps'] > (int) $rules['max_down_payment_bps']) {
                $errors['min_down_payment_bps'] = 'Entrada mínima não pode ser maior que a entrada máxima.';
            }
        }

        if (isset($rules['max_installments']) && $rules['max_installments'] !== null && $rules['max_installments'] !== '') {
            $maxAllowed = $this->maxInstallmentsForAmount($totalCents, $platformRules);
            if ((int) $rules['max_installments'] > $maxAllowed) {
                $errors['max_installments'] = "Com preço R$ ".number_format($priceBrl, 2, ',', '.').", o máximo permitido pela plataforma é {$maxAllowed}x.";
            }
        }

        if (isset($rules['down_payment_cents']) && $rules['down_payment_cents'] !== null && $rules['down_payment_cents'] !== '') {
            $down = (int) $rules['down_payment_cents'];
            if ($down < 0 || $down >= $totalCents) {
                $errors['down_payment_cents'] = 'Entrada fixa deve ser menor que o valor total.';
            }
        }

        return $errors;
    }

    /**
     * @param  array{name: string, email: string, document: string, phone?: string}  $consumer
     * @param  array<string, mixed>  $rules  merged rules
     * @return array<string, mixed>
     */
    public function buildPlanPayload(
        Order $order,
        Product $product,
        array $consumer,
        array $rules,
        int $installmentCount,
    ): array {
        $totalCents = MoneyMinorUnits::toMinorUnits((float) $order->amount, 'BRL');
        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? ''));
        $phone = trim((string) ($consumer['phone'] ?? ''));
        $phoneDigits = preg_replace('/\D/', '', $phone);
        $phoneE164 = strlen($phoneDigits) >= 8 ? '+'.$phoneDigits : null;

        $body = [
            'total_cents' => $totalCents,
            'installment_count' => $installmentCount,
            'description' => $product->name,
            'external_ref' => 'order-'.$order->id,
            'contract_acceptance' => true,
            'consumer' => array_filter([
                'name' => trim((string) ($consumer['name'] ?? '')),
                'email' => trim((string) ($consumer['email'] ?? '')),
                'document' => $document,
                'phone' => $phoneE164,
            ], static fn ($v) => $v !== null && $v !== ''),
        ];

        foreach (['down_payment_cents', 'min_down_payment_bps', 'max_down_payment_bps'] as $key) {
            if (isset($rules[$key]) && $rules[$key] !== null && $rules[$key] !== '') {
                $body[$key] = (int) $rules[$key];
            }
        }

        foreach (['early_payment_discount_bps', 'payoff_discount_bps', 'overdue_payoff_discount_bps'] as $key) {
            $val = (int) ($rules[$key] ?? 0);
            if ($val > 0) {
                $body[$key] = $val;
            }
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createPlan(array $credentials, array $body, string $idempotencyKey): array
    {
        return $this->driver()->createPixParceladoPlan($credentials, $body, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $planResponse
     * @return array{plan_id: string|null, payment_id: string|null, pix_copy_paste: string|null, pix_qr_code: string|null}
     */
    public function extractPlanCheckoutData(array $planResponse): array
    {
        $planId = is_string($planResponse['id'] ?? null) ? $planResponse['id'] : null;
        $paymentId = is_string($planResponse['first_payment_id'] ?? null) ? $planResponse['first_payment_id'] : null;
        $copy = is_string($planResponse['pix_copy_paste'] ?? null) ? $planResponse['pix_copy_paste'] : null;
        $qr = is_string($planResponse['pix_qr_code'] ?? null) ? $planResponse['pix_qr_code'] : null;

        return [
            'plan_id' => $planId,
            'payment_id' => $paymentId,
            'pix_copy_paste' => $copy,
            'pix_qr_code' => $qr,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $checkoutConfig
     * @return array<string, mixed>
     */
    public function productRulesFromConfig(?array $checkoutConfig): array
    {
        $defaults = Product::defaultCheckoutConfig()['pix_parcelado'] ?? [];
        $stored = is_array($checkoutConfig['pix_parcelado'] ?? null) ? $checkoutConfig['pix_parcelado'] : [];

        return array_replace_recursive($defaults, $stored);
    }

    /**
     * Map merged rules to SDK mount options (payment link field names).
     *
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    public function sdkOptionsFromRules(array $merged): array
    {
        $opts = [];
        if (! empty($merged['max_installments'])) {
            $opts['parcelado_max_installments'] = (int) $merged['max_installments'];
        }
        if (isset($merged['down_payment_cents']) && $merged['down_payment_cents'] !== null && $merged['down_payment_cents'] !== '') {
            $opts['parcelado_down_payment_cents'] = (int) $merged['down_payment_cents'];
        }
        if (isset($merged['min_down_payment_bps']) && $merged['min_down_payment_bps'] !== null && $merged['min_down_payment_bps'] !== '') {
            $opts['parcelado_min_down_payment_bps'] = (int) $merged['min_down_payment_bps'];
        }
        if (! empty($merged['max_down_payment_bps'])) {
            $opts['parcelado_max_down_payment_bps'] = (int) $merged['max_down_payment_bps'];
        }

        return $opts;
    }

    /**
     * Resolve valor da entrada (1ª parcela) em centavos para exibição do PIX.
     *
     * @param  array<string, mixed>  $planResult
     */
    public function resolveDownPaymentCentsFromPlanResult(array $planResult, ?int $fallbackCents = null): ?int
    {
        foreach (['down_payment_cents', 'downPaymentCents'] as $key) {
            if (isset($planResult[$key]) && is_numeric($planResult[$key]) && (int) $planResult[$key] > 0) {
                return (int) $planResult[$key];
            }
        }

        $installments = $planResult['installments'] ?? null;
        if (is_array($installments)) {
            foreach ($installments as $installment) {
                if (! is_array($installment)) {
                    continue;
                }
                $sequence = (int) ($installment['sequence'] ?? 0);
                if ($sequence !== 1 && $sequence !== 0) {
                    continue;
                }
                $amount = (int) ($installment['amount_cents'] ?? 0);
                if ($amount > 0) {
                    return $amount;
                }
            }
            $first = $installments[0] ?? null;
            if (is_array($first) && (int) ($first['amount_cents'] ?? 0) > 0) {
                return (int) $first['amount_cents'];
            }
        }

        return $fallbackCents !== null && $fallbackCents > 0 ? $fallbackCents : null;
    }

    private function driver(): CajuPayDriver
    {
        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            throw new \RuntimeException('Driver CajuPay indisponível.');
        }

        return $driver;
    }
}
