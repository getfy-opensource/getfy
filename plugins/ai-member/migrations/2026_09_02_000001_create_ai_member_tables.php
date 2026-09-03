<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_member_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->text('api_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_member_agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('product_id', 36)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->boolean('enabled')->default(false);
            $table->string('name', 120)->default('Assistente');
            $table->string('gender', 20)->default('neutral');
            $table->text('personality')->nullable();
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->unsignedInteger('max_tokens')->default(800);
            $table->text('system_instructions')->nullable();
            $table->text('welcome_message')->nullable();
            $table->string('widget_icon', 512)->nullable();
            $table->string('widget_color', 32)->nullable();
            $table->boolean('allow_image')->default(true);
            $table->boolean('allow_audio')->default(true);
            $table->json('orchestration_profile')->nullable();
            $table->timestamp('knowledge_indexed_at')->nullable();
            $table->unsignedInteger('knowledge_chunks_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
        });

        Schema::create('ai_member_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('product_id', 36);
            $table->unsignedBigInteger('tenant_id');
            $table->string('title', 255);
            $table->text('content');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        Schema::create('ai_member_knowledge_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('product_id', 36);
            $table->unsignedBigInteger('tenant_id');
            $table->string('source_type', 40);
            $table->string('source_id', 64)->nullable();
            $table->string('title', 512)->nullable();
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->string('content_hash', 64);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'source_type']);
            $table->unique(['product_id', 'source_type', 'source_id'], 'ai_member_chunks_source_unique');
        });

        Schema::create('ai_member_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('product_id', 36);
            $table->unsignedBigInteger('user_id');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'user_id']);
        });

        Schema::create('ai_member_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('role', 20);
            $table->text('content');
            $table->json('attachments')->nullable();
            $table->string('model_used', 120)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->string('intent', 40)->nullable();
            $table->decimal('cost_estimate', 10, 6)->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('ai_member_conversations')->cascadeOnDelete();
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_member_messages');
        Schema::dropIfExists('ai_member_conversations');
        Schema::dropIfExists('ai_member_knowledge_chunks');
        Schema::dropIfExists('ai_member_documents');
        Schema::dropIfExists('ai_member_agents');
        Schema::dropIfExists('ai_member_connections');
    }
};
