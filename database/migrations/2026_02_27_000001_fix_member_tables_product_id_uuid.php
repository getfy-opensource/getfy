<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix product_id / related_product_id in member_* tables when products.id is UUID (char 36)
 * but these tables still have bigInteger. Run when you see "Data truncated for column 'product_id'"
 * on Member Builder or other member-area operations.
 * Only alters tables where the column is still integer; re-adds FK only if all existing values
 * exist in products.id (so we don't break tables with orphaned integer IDs).
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> table => list of columns */
    private const TABLES = [
        'member_area_domains' => ['product_id'],
        'member_sections' => ['product_id'],
        'member_modules' => ['product_id', 'related_product_id'],
        'member_lessons' => ['product_id'],
        'member_lesson_progress' => ['product_id'],
        'member_internal_products' => ['product_id', 'related_product_id'],
        'member_turmas' => ['product_id'],
        'member_comments' => ['product_id'],
        'member_community_pages' => ['product_id'],
        'member_certificates_issued' => ['product_id'],
        'member_push_subscriptions' => ['product_id'],
        'member_achievement_unlocks' => ['product_id'],
    ];

    private const NULLABLE_COLUMNS = [
        'member_modules' => ['related_product_id'],
        'member_internal_products' => ['related_product_id'],
    ];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            foreach (self::TABLES as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue;
                    }
                    $col = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?", [$table, $column]);
                    if (! $col || in_array($col->data_type, ['character', 'character varying'])) {
                        continue;
                    }
                    DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$table}_{$column}_foreign\"");
                    DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE VARCHAR(36) USING \"{$column}\"::text");
                    $nullable = isset(self::NULLABLE_COLUMNS[$table]) && in_array($column, self::NULLABLE_COLUMNS[$table], true);
                    $orphans = DB::table($table)->whereNotNull($column)->whereNotIn($column, DB::table('products')->select('id'))->count();
                    if ($orphans === 0) {
                        Schema::table($table, function (Blueprint $t) use ($column, $nullable) {
                            $fk = $t->foreign($column)->references('id')->on('products');
                            ($nullable || $column === 'related_product_id') ? $fk->nullOnDelete() : $fk->cascadeOnDelete();
                        });
                    }
                }
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
        if (! $productIdType || $productIdType->DATA_TYPE !== 'char' || (int) $productIdType->CHARACTER_MAXIMUM_LENGTH !== 36) {
            return;
        }

        foreach (self::TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $col = DB::selectOne(
                    "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$db, $table, $column]
                );
                if (! $col || $col->DATA_TYPE === 'char') {
                    continue;
                }

                $fkName = DB::selectOne(
                    "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = 'products' LIMIT 1",
                    [$db, $table, $column]
                );
                if ($fkName && $fkName->CONSTRAINT_NAME) {
                    DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName->CONSTRAINT_NAME}`");
                }
                $nullable = isset(self::NULLABLE_COLUMNS[$table]) && in_array($column, self::NULLABLE_COLUMNS[$table], true);
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` CHAR(36) " . ($nullable ? 'NULL' : 'NOT NULL'));

                // Só recria a FK se todos os valores atuais existirem em products.id (evita erro com IDs antigos inteiros).
                $orphans = DB::table($table)->whereNotNull($column)->whereNotIn($column, DB::table('products')->select('id'))->count();
                if ($orphans === 0) {
                    Schema::table($table, function (Blueprint $t) use ($column, $nullable) {
                        $fk = $t->foreign($column)->references('id')->on('products');
                        if ($nullable || $column === 'related_product_id') {
                            $fk->nullOnDelete();
                        } else {
                            $fk->cascadeOnDelete();
                        }
                    });
                }
            }
        }
    }

    public function down(): void
    {
        // Irreversible once data is UUIDs.
    }
};
