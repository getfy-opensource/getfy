<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_member_conversations', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'user_id']);
            $table->timestamp('archived_at')->nullable()->after('last_message_at');
            $table->string('title', 255)->nullable()->after('archived_at');
            $table->index(['product_id', 'user_id', 'archived_at']);
        });

        Schema::table('ai_member_agents', function (Blueprint $table) {
            $table->string('widget_color_source', 20)->default('theme')->after('widget_color');
            $table->string('intro_headline', 255)->nullable()->after('welcome_message');
        });
    }

    public function down(): void
    {
        Schema::table('ai_member_agents', function (Blueprint $table) {
            $table->dropColumn(['widget_color_source', 'intro_headline']);
        });

        Schema::table('ai_member_conversations', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'user_id', 'archived_at']);
            $table->dropColumn(['archived_at', 'title']);
            $table->unique(['product_id', 'user_id']);
        });
    }
};
