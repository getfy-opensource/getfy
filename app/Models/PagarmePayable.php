<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recebível da Pagar.me espelhado de GET /payables (valores em centavos).
 *
 * @property int $id
 * @property int $pagarme_account_id
 * @property string $payable_id
 * @property string|null $charge_id
 * @property int|null $order_id
 * @property int|null $tenant_id
 * @property string $type
 * @property string $status
 * @property string|null $payment_method
 * @property int|null $installment
 * @property int $amount_cents
 * @property int $fee_cents
 * @property int $anticipation_fee_cents
 * @property int $fraud_coverage_fee_cents
 * @property \Illuminate\Support\Carbon|null $payment_date
 * @property \Illuminate\Support\Carbon|null $accrual_at
 * @property string|null $settlement_id
 * @property \Illuminate\Support\Carbon|null $gateway_created_at
 * @property-read Order|null $order
 */
class PagarmePayable extends Model
{
    public const TYPE_CREDIT = 'credit';

    public const STATUS_WAITING_FUNDS = 'waiting_funds';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'pagarme_account_id', 'payable_id', 'charge_id', 'order_id', 'tenant_id', 'type', 'status',
        'payment_method', 'installment', 'amount_cents', 'fee_cents', 'anticipation_fee_cents',
        'fraud_coverage_fee_cents', 'payment_date', 'accrual_at', 'settlement_id', 'gateway_created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'order_id' => 'integer',
            'installment' => 'integer',
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'anticipation_fee_cents' => 'integer',
            'fraud_coverage_fee_cents' => 'integer',
            'payment_date' => 'date',
            'accrual_at' => 'datetime',
            'gateway_created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function netCents(): int
    {
        return $this->amount_cents - $this->fee_cents - $this->anticipation_fee_cents - $this->fraud_coverage_fee_cents;
    }
}
