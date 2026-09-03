<?php

namespace Plugins\AiMember\Models;

use Illuminate\Database\Eloquent\Model;

class AiMemberConnection extends Model
{
    protected $table = 'ai_member_connections';

    protected $fillable = [
        'tenant_id',
        'api_key',
        'is_active',
        'last_tested_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }

        return $query->where('tenant_id', $tenantId);
    }

    public function isConfigured(): bool
    {
        $key = $this->api_key;

        return is_string($key) && trim($key) !== '';
    }
}
