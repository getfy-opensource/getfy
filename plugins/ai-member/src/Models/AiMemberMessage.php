<?php

namespace Plugins\AiMember\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMemberMessage extends Model
{
    use HasUuids;

    protected $table = 'ai_member_messages';

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'attachments',
        'model_used',
        'tokens_in',
        'tokens_out',
        'intent',
        'cost_estimate',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'cost_estimate' => 'float',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiMemberConversation::class, 'conversation_id');
    }
}
