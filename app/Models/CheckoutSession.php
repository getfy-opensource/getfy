<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutSession extends Model
{
    public const STEP_VISIT = 'visit';

    public const STEP_FORM_STARTED = 'form_started';

    public const STEP_FORM_FILLED = 'form_filled';

    public const STEP_CONVERTED = 'converted';

    /** Janela após interação no checkout para contar abandono (relatórios) e disparar webhook. */
    public const ABANDONMENT_GRACE_MINUTES = 10;

    protected $fillable = [
        'tenant_id', 'product_id', 'product_offer_id', 'subscription_plan_id',
        'checkout_slug', 'session_token', 'step', 'form_started_at', 'form_filled_at',
        'email', 'name',
        'customer_ip', 'order_id', 'utm_source', 'utm_medium', 'utm_campaign',
        'abandoned_webhook_fired_at',
        'recovery_email_stage', 'recovery_email_last_sent_at', 'recovery_email_next_at',
    ];

    protected function casts(): array
    {
        return [
            'form_started_at' => 'datetime',
            'form_filled_at' => 'datetime',
            'abandoned_webhook_fired_at' => 'datetime',
            'recovery_email_last_sent_at' => 'datetime',
            'recovery_email_next_at' => 'datetime',
        ];
    }

    public static function abandonmentEligibilityCutoff(): \Illuminate\Support\Carbon
    {
        return now()->subMinutes(self::ABANDONMENT_GRACE_MINUTES);
    }

    /**
     * Visitou o checkout, não pagou e já passou o período de graça desde a criação da sessão.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\CheckoutSession>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\CheckoutSession>
     */
    public function scopeWhereAbandonmentVisitEligible($query)
    {
        return $query
            ->where('step', self::STEP_VISIT)
            ->whereNull('order_id')
            ->where('created_at', '<=', self::abandonmentEligibilityCutoff());
    }

    /**
     * Iniciou ou preencheu o formulário, não pagou e já passou o período de graça desde a última interação relevante.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\CheckoutSession>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\CheckoutSession>
     */
    public function scopeWhereAbandonmentFormEligible($query)
    {
        $cutoff = self::abandonmentEligibilityCutoff();

        return $query
            ->whereIn('step', [self::STEP_FORM_STARTED, self::STEP_FORM_FILLED])
            ->whereNull('order_id')
            ->whereRaw(
                'COALESCE(form_filled_at, form_started_at, created_at) <= ?',
                [$cutoff]
            );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $tenantId);
    }
}
