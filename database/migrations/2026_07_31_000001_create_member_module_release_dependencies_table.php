<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL confirma o CREATE TABLE antes de uma falha posterior de DDL (ex.: constraint).
        // Como esta migration ainda não foi registrada, remove qualquer tabela parcial da tentativa anterior.
        Schema::dropIfExists('member_module_release_dependencies');

        Schema::create('member_module_release_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_module_id');
            $table->foreignId('required_member_module_id');
            $table->unsignedTinyInteger('minimum_progress_percent')->default(100);
            $table->timestamps();

            $table->unique(['member_module_id', 'required_member_module_id'], 'member_module_release_dependency_unique');
            $table->foreign('member_module_id', 'mm_release_dep_module_fk')
                ->references('id')->on('member_modules')->cascadeOnDelete();
            $table->foreign('required_member_module_id', 'mm_release_dep_required_fk')
                ->references('id')->on('member_modules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_module_release_dependencies');
    }
};
