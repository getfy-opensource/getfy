<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Antecipação de recebíveis da conta Pagar.me (bulk_anticipations).
 *
 * @property int $id
 * @property int $pagarme_account_id
 * @property string|null $anticipation_id
 * @property string|null $idempotency_key
 * @property int $requested_amount_cents
 * @property int|null $amount_cents
 * @property int|null $fee_cents
 * @property int|null $anticipation_fee_cents
 * @property string $status
 * @property string|null $timeframe
 * @property \Illuminate\Support\Carbon|null $payment_date
 * @property int|null $requested_by_user_id
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $gateway_created_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class PagarmeAnticipation extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'pagarme_account_id', 'anticipation_id', 'idempotency_key', 'requested_amount_cents', 'amount_cents',
        'fee_cents', 'anticipation_fee_cents', 'status', 'timeframe', 'payment_date', 'requested_by_user_id',
        'requested_ip', 'error_message', 'gateway_created_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount_cents' => 'integer',
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'anticipation_fee_cents' => 'integer',
            'payment_date' => 'date',
            'gateway_created_at' => 'datetime',
        ];
    }
}
