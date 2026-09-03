<?php

namespace Plugins\AiMember\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMemberDocument extends Model
{
    use HasUuids;

    protected $table = 'ai_member_documents';

    protected $fillable = [
        'product_id',
        'tenant_id',
        'title',
        'content',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
