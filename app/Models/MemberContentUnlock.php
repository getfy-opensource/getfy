<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberContentUnlock extends Model
{
    protected $fillable = ['user_id', 'member_module_id', 'unlocked_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(MemberModule::class, 'member_module_id');
    }

    public function unlockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }
}
