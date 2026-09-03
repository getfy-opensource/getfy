<?php

namespace Plugins\AiMember\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMemberKnowledgeChunk extends Model
{
    use HasUuids;

    protected $table = 'ai_member_knowledge_chunks';

    protected $fillable = [
        'product_id',
        'tenant_id',
        'source_type',
        'source_id',
        'title',
        'content',
        'embedding',
        'content_hash',
        'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'indexed_at' => 'datetime',
        ];
    }
}
