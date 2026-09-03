<?php

namespace Plugins\AiMember\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiMemberAgent extends Model
{
    use HasUuids;

    protected $table = 'ai_member_agents';

    protected $fillable = [
        'product_id',
        'tenant_id',
        'enabled',
        'name',
        'gender',
        'personality',
        'temperature',
        'max_tokens',
        'system_instructions',
        'welcome_message',
        'intro_headline',
        'widget_icon',
        'widget_color',
        'widget_color_source',
        'allow_image',
        'allow_audio',
        'orchestration_profile',
        'knowledge_indexed_at',
        'knowledge_chunks_count',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'temperature' => 'float',
            'max_tokens' => 'integer',
            'allow_image' => 'boolean',
            'allow_audio' => 'boolean',
            'orchestration_profile' => 'array',
            'knowledge_indexed_at' => 'datetime',
            'knowledge_chunks_count' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AiMemberDocument::class, 'product_id', 'product_id')->orderBy('position');
    }

    public function toPublicWidgetConfig(?string $themePrimary = null): array
    {
        $source = $this->widget_color_source ?: 'theme';
        $resolvedColor = ($source === 'custom' && $this->widget_color)
            ? $this->widget_color
            : ($themePrimary ?: $this->widget_color ?: '#0ea5e9');

        return [
            'enabled' => (bool) $this->enabled,
            'name' => $this->name,
            'gender' => $this->gender,
            'welcome_message' => $this->welcome_message,
            'intro_headline' => $this->intro_headline,
            'widget_icon' => $this->widget_icon,
            'widget_color' => $resolvedColor,
            'widget_color_source' => $source,
            'theme_primary' => $themePrimary ?: '#0ea5e9',
            'allow_image' => (bool) $this->allow_image,
            'allow_audio' => (bool) $this->allow_audio,
        ];
    }

    public function genderPronounHint(): string
    {
        return match ($this->gender) {
            'male' => 'Use pronomes masculinos ao se referir a si mesmo.',
            'female' => 'Use pronomes femininos ao se referir a si mesma.',
            default => 'Use linguagem neutra ao se referir a si mesmo.',
        };
    }
}
