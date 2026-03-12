<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('member_notifications');

        Schema::create('member_notifications', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            // products.id é UUID (CHAR 36) nesta base; users.id é bigint unsigned
            $table->string('product_id', 36);
            $table->unsignedBigInteger('user_id');
            $table->string('type', 64)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notifications');
    }
};
