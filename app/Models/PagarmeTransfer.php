<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Transferência da conta Pagar.me para a conta bancária cadastrada (POST/GET /transfers).
 *
 * @property int $id
 * @property int $pagarme_account_id
 * @property string|null $transfer_id
 * @property string|null $idempotency_key
 * @property int $amount_cents
 * @property int|null $fee_cents
 * @property string $status
 * @property string|null $type
 * @property \Illuminate\Support\Carbon|null $funding_estimated_at
 * @property array<string, string>|null $bank_account_masked
 * @property int|null $requested_by_user_id
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $gateway_created_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class PagarmeTransfer extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'pagarme_account_id', 'transfer_id', 'idempotency_key', 'amount_cents', 'fee_cents', 'status', 'type',
        'funding_estimated_at', 'bank_account_masked', 'requested_by_user_id', 'requested_ip', 'error_message',
        'gateway_created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'funding_estimated_at' => 'datetime',
            'bank_account_masked' => 'array',
            'gateway_created_at' => 'datetime',
        ];
    }
}
