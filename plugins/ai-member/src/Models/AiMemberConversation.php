<?php

namespace Plugins\AiMember\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiMemberConversation extends Model
{
    use HasUuids;

    protected $table = 'ai_member_conversations';

    protected $fillable = [
        'product_id',
        'user_id',
        'last_message_at',
        'archived_at',
        'title',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMemberMessage::class, 'conversation_id')->orderBy('created_at');
    }
}
