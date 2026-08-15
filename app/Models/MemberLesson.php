<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class MemberLesson extends Model
{
    public const TYPE_VIDEO = 'video';

    public const TYPE_LINK = 'link';

    public const TYPE_PDF = 'pdf';

    /** Slides / leitura em tela com navegação por páginas (pdf.js), distinto de "Material" (download). */
    public const TYPE_PDF_PRESENTATION = 'pdf_presentation';

    /** Leitor de PDF completo (marcações, miniaturas, curtidas) — mesma origem de arquivo que pdf_presentation. */
    public const TYPE_PDF_READER = 'pdf_reader';

    public const TYPE_TEXT = 'text';

    protected $fillable = [
        'member_module_id',
        'product_id',
        'title',
        'position',
        'type',
        'content_url',
        'link_title',
        'content_files',
        'support_files',
        'useful_links',
        'release_after_days',
        'release_at_date',
        'access_duration_days',
        'content_text',
        'duration_seconds',
        'is_free',
        'watermark_enabled',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_seconds' => 'integer',
            'is_free' => 'boolean',
            'watermark_enabled' => 'boolean',
            'likes_count' => 'integer',
            'content_files' => 'array',
            'support_files' => 'array',
            'useful_links' => 'array',
            'release_after_days' => 'integer',
            'release_at_date' => 'date:Y-m-d',
            'access_duration_days' => 'integer',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(MemberModule::class, 'member_module_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(MemberComment::class, 'member_lesson_id');
    }

    public function lessonLikes(): HasMany
    {
        return $this->hasMany(MemberLessonLike::class, 'member_lesson_id');
    }

    public function pdfAnnotations(): HasMany
    {
        return $this->hasMany(MemberLessonPdfAnnotation::class, 'member_lesson_id');
    }

    public function releaseDependencies(): HasMany
    {
        return $this->hasMany(MemberLessonReleaseDependency::class, 'member_lesson_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function onlyExistingColumns(array $attributes): array
    {
        $table = (new static)->getTable();
        $filtered = [];
        static $cache = [];

        foreach ($attributes as $key => $value) {
            $cacheKey = "{$table}.{$key}";
            if (! array_key_exists($cacheKey, $cache)) {
                $cache[$cacheKey] = Schema::hasColumn($table, $key);
            }
            if ($cache[$cacheKey]) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
