<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('member_lesson_release_dependencies');
        Schema::create('member_lesson_release_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_lesson_id');
            $table->foreignId('required_member_lesson_id');
            $table->timestamps();
            $table->unique(['member_lesson_id', 'required_member_lesson_id'], 'ml_release_dependency_unique');
            $table->foreign('member_lesson_id', 'ml_release_dep_lesson_fk')->references('id')->on('member_lessons')->cascadeOnDelete();
            $table->foreign('required_member_lesson_id', 'ml_release_dep_required_fk')->references('id')->on('member_lessons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_lesson_release_dependencies');
    }
};
