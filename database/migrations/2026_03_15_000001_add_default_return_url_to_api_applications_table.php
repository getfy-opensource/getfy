<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('api_applications', 'default_return_url')) {
                $table->string('default_return_url', 512)->nullable()->after('webhook_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('api_applications', function (Blueprint $table) {
            $table->dropColumn('default_return_url');
        });
    }
};

