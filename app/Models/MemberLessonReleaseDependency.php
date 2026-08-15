<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberLessonReleaseDependency extends Model
{
    protected $fillable = ['member_lesson_id', 'required_member_lesson_id'];

    public function requiredLesson(): BelongsTo
    {
        return $this->belongsTo(MemberLesson::class, 'required_member_lesson_id');
    }
}
