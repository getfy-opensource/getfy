<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Liquidação (pagamento efetivo) da Pagar.me, de GET /settlements.
 *
 * @property int $id
 * @property string $settlement_id
 * @property int $amount_cents
 * @property string|null $product
 * @property string|null $card_brand
 * @property string|null $status
 * @property \Illuminate\Support\Carbon|null $payment_date
 */
class PagarmeSettlement extends Model
{
    protected $fillable = [
        'pagarme_account_id', 'settlement_id', 'amount_cents', 'product', 'card_brand', 'status', 'payment_date',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'payment_date' => 'date',
        ];
    }
}
