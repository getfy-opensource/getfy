<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('member_lessons', 'content_files')) {
                $table->json('content_files')->nullable()->after('link_title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('member_lessons', function (Blueprint $table) {
            $table->dropColumn('content_files');
        });
    }
};

