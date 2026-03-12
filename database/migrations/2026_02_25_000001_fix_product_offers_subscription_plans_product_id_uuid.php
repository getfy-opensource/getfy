<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix product_id column type in product_offers and subscription_plans when
 * products.id is UUID (char 36) but these tables still have bigInteger product_id.
 * Run when you see "Data truncated for column 'product_id'" on offer/plan create.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            foreach (['product_offers', 'subscription_plans'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'product_id')) {
                    continue;
                }
                $col = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'product_id'", [$table]);
                if (! $col || in_array($col->data_type, ['character', 'character varying'])) {
                    continue;
                }
                DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$table}_product_id_foreign\"");
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"product_id\" TYPE VARCHAR(36) USING \"product_id\"::text");
                Schema::table($table, fn (Blueprint $t) => $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete());
            }
            return;
        }

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $db = DB::getDatabaseName();

        $productIdType = DB::selectOne(
            "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'products' AND COLUMN_NAME = 'id'",
            [$db]
        );
        if (!$productIdType || $productIdType->DATA_TYPE !== 'char' || (int) $productIdType->CHARACTER_MAXIMUM_LENGTH !== 36) {
            return;
        }

        foreach (['product_offers', 'subscription_plans'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'product_id')) {
                continue;
            }
            $col = DB::selectOne(
                "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'product_id'",
                [$db, $table]
            );
            if (!$col || $col->DATA_TYPE === 'char') {
                continue;
            }

            $fkName = DB::selectOne(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'product_id' AND REFERENCED_TABLE_NAME = 'products' LIMIT 1",
                [$db, $table]
            );
            if ($fkName && $fkName->CONSTRAINT_NAME) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName->CONSTRAINT_NAME}`");
            }
            DB::statement("ALTER TABLE `{$table}` MODIFY product_id CHAR(36) NOT NULL");
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Irreversible without losing data if products.id is UUID and these columns already store UUIDs.
    }
};
