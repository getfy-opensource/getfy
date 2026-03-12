<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix product_user.product_id when products.id is UUID (char 36)
 * but product_user still has bigInteger. Run when you see "Data truncated for column 'product_id'"
 * on product_user insert (e.g. checkout grant access, ProcessPaymentWebhook).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            if (! Schema::hasTable('product_user') || ! Schema::hasColumn('product_user', 'product_id')) {
                return;
            }
            $col = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'product_user' AND column_name = 'product_id'");
            if (! $col || in_array($col->data_type, ['character', 'character varying'])) {
                return;
            }
            DB::statement('ALTER TABLE "product_user" DROP CONSTRAINT IF EXISTS "product_user_product_id_foreign"');
            DB::statement('ALTER TABLE "product_user" DROP CONSTRAINT IF EXISTS "product_user_product_id_user_id_unique"');
            DB::statement('ALTER TABLE "product_user" ALTER COLUMN "product_id" TYPE VARCHAR(36) USING "product_id"::text');
            DB::table('product_user')->whereNotIn('product_id', DB::table('products')->select('id'))->delete();
            Schema::table('product_user', function (Blueprint $t) {
                $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $t->unique(['product_id', 'user_id']);
            });
            return;
        }

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        if (! Schema::hasTable('product_user') || ! Schema::hasColumn('product_user', 'product_id')) {
            return;
        }

        $db = DB::getDatabaseName();

        $productIdType = DB::selectOne(
            "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'products' AND COLUMN_NAME = 'id'",
            [$db]
        );
        if (! $productIdType || $productIdType->DATA_TYPE !== 'char' || (int) $productIdType->CHARACTER_MAXIMUM_LENGTH !== 36) {
            return;
        }

        $col = DB::selectOne(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'product_user' AND COLUMN_NAME = 'product_id'",
            [$db]
        );
        if (! $col || $col->DATA_TYPE === 'char') {
            return;
        }

        $fkName = DB::selectOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'product_user' AND COLUMN_NAME = 'product_id' AND REFERENCED_TABLE_NAME = 'products' LIMIT 1",
            [$db]
        );
        if ($fkName && $fkName->CONSTRAINT_NAME) {
            DB::statement("ALTER TABLE `product_user` DROP FOREIGN KEY `{$fkName->CONSTRAINT_NAME}`");
        }

        $uniqueName = DB::selectOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'product_user' AND CONSTRAINT_TYPE = 'UNIQUE' LIMIT 1",
            [$db]
        );
        if ($uniqueName && $uniqueName->CONSTRAINT_NAME) {
            DB::statement("ALTER TABLE `product_user` DROP INDEX `{$uniqueName->CONSTRAINT_NAME}`");
        }

        DB::statement('ALTER TABLE `product_user` MODIFY `product_id` CHAR(36) NOT NULL');

        // Remove linhas órfãs (product_id que não existem em products) para permitir recriar a FK
        DB::table('product_user')->whereNotIn('product_id', DB::table('products')->select('id'))->delete();

        Schema::table('product_user', function (Blueprint $t) {
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $t->unique(['product_id', 'user_id']);
        });
    }

    public function down(): void
    {
        // Irreversible once products use UUID.
    }
};
