<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_modules', function (Blueprint $table) {
            if (! Schema::hasColumn('member_modules', 'release_after_days')) {
                $table->unsignedInteger('release_after_days')->nullable()->after('external_url');
            }
            if (! Schema::hasColumn('member_modules', 'release_at_date')) {
                $table->date('release_at_date')->nullable()->after('release_after_days');
            }
        });

        Schema::table('member_lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('member_lessons', 'release_after_days')) {
                $table->unsignedInteger('release_after_days')->nullable()->after('content_files');
            }
            if (! Schema::hasColumn('member_lessons', 'release_at_date')) {
                $table->date('release_at_date')->nullable()->after('release_after_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('member_modules', function (Blueprint $table) {
            if (Schema::hasColumn('member_modules', 'release_at_date')) {
                $table->dropColumn('release_at_date');
            }
            if (Schema::hasColumn('member_modules', 'release_after_days')) {
                $table->dropColumn('release_after_days');
            }
        });

        Schema::table('member_lessons', function (Blueprint $table) {
            if (Schema::hasColumn('member_lessons', 'release_at_date')) {
                $table->dropColumn('release_at_date');
            }
            if (Schema::hasColumn('member_lessons', 'release_after_days')) {
                $table->dropColumn('release_after_days');
            }
        });
    }
};

