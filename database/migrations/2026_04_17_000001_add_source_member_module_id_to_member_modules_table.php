<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_modules', function (Blueprint $table) {
            $table->foreignId('source_member_module_id')
                ->nullable()
                ->constrained('member_modules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('member_modules', function (Blueprint $table) {
            $table->dropForeign(['source_member_module_id']);
        });
    }
};
