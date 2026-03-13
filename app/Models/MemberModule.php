<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberModule extends Model
{
    protected $fillable = ['member_section_id', 'product_id', 'title', 'position', 'thumbnail', 'show_title_on_cover', 'related_product_id', 'access_type', 'external_url', 'release_after_days'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'show_title_on_cover' => 'boolean', 'release_after_days' => 'integer'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(MemberSection::class, 'member_section_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(MemberLesson::class, 'member_module_id')->orderBy('position');
    }

    public function contentUnlocks(): HasMany
    {
        return $this->hasMany(MemberContentUnlock::class, 'member_module_id');
    }

    /**
     * Verifica se o módulo está bloqueado (drip) para um aluno.
     * Retorna null se liberado, ou a data de liberação se bloqueado.
     */
    public function dripUnlocksAt(int $userId): ?\Carbon\Carbon
    {
        if ($this->release_after_days <= 0) {
            return null;
        }

        // Se foi desbloqueado manualmente, está liberado
        if (MemberContentUnlock::where('user_id', $userId)->where('member_module_id', $this->id)->exists()) {
            return null;
        }

        // Busca a data do pedido mais antigo completado
        $orderDate = \App\Models\Order::where('user_id', $userId)
            ->where('product_id', $this->product_id)
            ->where('status', 'completed')
            ->orderBy('created_at')
            ->value('created_at');

        if (! $orderDate) {
            // Sem pedido — pode ter sido adicionado manualmente; usa created_at do user_product pivot
            $pivot = \Illuminate\Support\Facades\DB::table('user_product')
                ->where('user_id', $userId)
                ->where('product_id', $this->product_id)
                ->first();
            $orderDate = $pivot?->created_at ? \Carbon\Carbon::parse($pivot->created_at) : null;
        }

        if (! $orderDate) {
            return \Carbon\Carbon::now()->addDays($this->release_after_days);
        }

        $unlocksAt = \Carbon\Carbon::parse($orderDate)->addDays($this->release_after_days);

        return $unlocksAt->isPast() ? null : $unlocksAt;
    }
}
