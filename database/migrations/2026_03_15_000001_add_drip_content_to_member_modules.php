<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_modules', function (Blueprint $table) {
            $table->unsignedInteger('release_after_days')->default(0)->after('position');
        });

        Schema::create('member_content_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_module_id')->constrained('member_modules')->cascadeOnDelete();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'member_module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_content_unlocks');

        Schema::table('member_modules', function (Blueprint $table) {
            $table->dropColumn('release_after_days');
        });
    }
};
