<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->char('combo_product_id', 36)->nullable()->after('member_area_config');
            $table->foreign('combo_product_id')->references('id')->on('products')->nullOnDelete();
        });

        Schema::table('product_offers', function (Blueprint $table) {
            $table->char('combo_product_id', 36)->nullable()->after('product_id');
            $table->foreign('combo_product_id')->references('id')->on('products')->nullOnDelete();
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->char('combo_product_id', 36)->nullable()->after('product_id');
            $table->foreign('combo_product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropForeign(['combo_product_id']);
            $table->dropColumn('combo_product_id');
        });

        Schema::table('product_offers', function (Blueprint $table) {
            $table->dropForeign(['combo_product_id']);
            $table->dropColumn('combo_product_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['combo_product_id']);
            $table->dropColumn('combo_product_id');
        });
    }
};
