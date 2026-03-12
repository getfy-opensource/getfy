<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PRODUCT_FK_TABLES = [
        'orders' => ['product_id'],
        'product_user' => ['product_id'],
        'coupons' => ['product_id'],
        'coupon_product' => ['product_id'],
        'product_offers' => ['product_id'],
        'subscription_plans' => ['product_id'],
        'subscriptions' => ['product_id'],
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

    /** Columns that allow NULL (product_id or related_product_id). */
    private const NULLABLE_PRODUCT_COLUMNS = [
        'coupons' => ['product_id'],
        'member_modules' => ['related_product_id'],
        'member_internal_products' => ['related_product_id'],
    ];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::transaction(function () {
                $this->runUpMySQL();
            });
            return;
        }

        if ($driver === 'pgsql') {
            DB::transaction(function () {
                $this->runUpPostgreSQL();
            });
            return;
        }
    }

    private function runUpMySQL(): void
    {
        $col = DB::selectOne(
            "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'products' AND COLUMN_NAME = 'id'",
            [DB::getDatabaseName()]
        );
        if ($col && $col->DATA_TYPE === 'char' && (int) $col->CHARACTER_MAXIMUM_LENGTH === 36) {
            return;
        }

        $mappingTable = '_product_uuid_mapping';
        if (Schema::hasTable($mappingTable)) {
            Schema::drop($mappingTable);
        }
        Schema::create($mappingTable, function (Blueprint $table) {
            $table->unsignedBigInteger('old_id')->primary();
            $table->char('new_id', 36);
        });

        $products = DB::table('products')->get();
        foreach ($products as $p) {
            $newId = (string) Str::uuid();
            DB::table($mappingTable)->insert(['old_id' => $p->id, 'new_id' => $newId]);
        }

        if (! Schema::hasColumn('products', 'id_new')) {
            Schema::table('products', function (Blueprint $table) {
                $table->char('id_new', 36)->nullable()->after('id');
            });
        }
        DB::table('products')->orderBy('id')->chunk(100, function ($rows) use ($mappingTable) {
            foreach ($rows as $p) {
                $newId = DB::table($mappingTable)->where('old_id', $p->id)->value('new_id');
                DB::table('products')->where('id', $p->id)->update(['id_new' => $newId]);
            }
        });

        foreach (array_keys(self::PRODUCT_FK_TABLES) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (self::PRODUCT_FK_TABLES[$table] as $column) {
                try {
                    Schema::table($table, function (Blueprint $t) use ($column) {
                        $t->dropForeign([$column]);
                    });
                } catch (\Throwable $e) {
                    // FK may already have been dropped in a previous failed run
                }
            }
        }

        DB::statement('ALTER TABLE products MODIFY id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE products DROP PRIMARY KEY');
        DB::statement('ALTER TABLE products DROP COLUMN id');
        DB::statement('ALTER TABLE products CHANGE id_new id CHAR(36) NOT NULL');
        DB::statement('ALTER TABLE products ADD PRIMARY KEY (id)');

        foreach (self::PRODUCT_FK_TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $indexesToRestore = $this->dropIndexesContainingColumn($table, $column);
                if (! Schema::hasColumn($table, $column . '_new')) {
                    Schema::table($table, function (Blueprint $t) use ($table, $column) {
                        $t->char($column . '_new', 36)->nullable()->after($column);
                    });
                }
                DB::table($mappingTable)->orderBy('old_id')->chunk(100, function ($mapRows) use ($table, $column) {
                    foreach ($mapRows as $m) {
                        DB::table($table)->where($column, $m->old_id)->update([$column . '_new' => $m->new_id]);
                    }
                });
                Schema::table($table, function (Blueprint $t) use ($column) {
                    $t->dropColumn($column);
                });
                $nullable = isset(self::NULLABLE_PRODUCT_COLUMNS[$table]) && in_array($column, self::NULLABLE_PRODUCT_COLUMNS[$table], true);
                DB::statement("ALTER TABLE {$table} CHANGE {$column}_new {$column} CHAR(36) " . ($nullable ? 'NULL' : 'NOT NULL'));
                $this->restoreIndexes($table, $indexesToRestore);
            }
        }

        Schema::drop($mappingTable);

        foreach (self::PRODUCT_FK_TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                Schema::table($table, function (Blueprint $t) use ($table, $column) {
                    $fk = $t->foreign($column)->references('id')->on('products');
                    $isNullable = $column === 'related_product_id' || (isset(self::NULLABLE_PRODUCT_COLUMNS[$table]) && in_array($column, self::NULLABLE_PRODUCT_COLUMNS[$table], true));
                    if ($isNullable) {
                        $fk->nullOnDelete();
                    } else {
                        $fk->cascadeOnDelete();
                    }
                });
            }
        }
    }

    /**
     * Drop all indexes (including primary) that contain the given column.
     * Returns definitions to restore later: [['name' => 'idx', 'columns' => ['a','b'], 'primary' => bool, 'unique' => bool], ...]
     */
    private function dropIndexesContainingColumn(string $table, string $column): array
    {
        $schema = DB::getDatabaseName();
        $indexNames = DB::select(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$schema, $table, $column]
        );
        $toRestore = [];
        foreach ($indexNames as $row) {
            $indexName = $row->INDEX_NAME;
            $cols = DB::select(
                'SELECT COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
                [$schema, $table, $indexName]
            );
            $columns = array_map(fn ($c) => $c->COLUMN_NAME, $cols);
            $isPrimary = $indexName === 'PRIMARY';
            $isUnique = ! $isPrimary && (DB::selectOne(
                'SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
                [$schema, $table, $indexName]
            ))->NON_UNIQUE == 0;
            $toRestore[] = ['name' => $indexName, 'columns' => $columns, 'primary' => $isPrimary, 'unique' => $isUnique];
            if ($isPrimary) {
                DB::statement("ALTER TABLE `{$table}` DROP PRIMARY KEY");
            } else {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
            }
        }
        return $toRestore;
    }

    private function restoreIndexes(string $table, array $definitions): void
    {
        foreach ($definitions as $def) {
            $cols = $def['columns'];
            $colsList = implode('`, `', $cols);
            if ($def['primary']) {
                DB::statement("ALTER TABLE `{$table}` ADD PRIMARY KEY (`{$colsList}`)");
            } elseif ($def['unique']) {
                DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$def['name']}` (`{$colsList}`)");
            } else {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$def['name']}` (`{$colsList}`)");
            }
        }
    }

    /**
     * PostgreSQL: convert products.id from bigint to varchar(36) UUID.
     * Uses ALTER COLUMN TYPE with USING cast (PG supports transactional DDL).
     */
    private function runUpPostgreSQL(): void
    {
        $col = DB::selectOne(
            "SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'products' AND column_name = 'id'"
        );
        if ($col && in_array($col->data_type, ['character', 'character varying'])) {
            return;
        }

        // Build UUID mapping for existing products (empty on fresh installs)
        $mapping = [];
        foreach (DB::table('products')->pluck('id') as $oldId) {
            $mapping[$oldId] = (string) Str::uuid();
        }

        // Drop all FK constraints
        foreach (self::PRODUCT_FK_TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$table}_{$column}_foreign\"");
            }
        }

        // Convert products.id from bigint to varchar(36)
        DB::statement('ALTER TABLE "products" DROP CONSTRAINT IF EXISTS "products_pkey"');
        DB::statement('ALTER TABLE "products" ALTER COLUMN "id" DROP DEFAULT');
        DB::statement('ALTER TABLE "products" ALTER COLUMN "id" TYPE VARCHAR(36) USING "id"::text');

        // Replace old bigint IDs with UUIDs
        foreach ($mapping as $oldId => $newUuid) {
            DB::table('products')->where('id', (string) $oldId)->update(['id' => $newUuid]);
        }

        DB::statement('ALTER TABLE "products" ALTER COLUMN "id" SET NOT NULL');
        DB::statement('ALTER TABLE "products" ADD PRIMARY KEY ("id")');
        DB::statement('DROP SEQUENCE IF EXISTS "products_id_seq"');

        // Convert FK columns to varchar(36) and update with UUIDs
        foreach (self::PRODUCT_FK_TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE VARCHAR(36) USING \"{$column}\"::text");
                foreach ($mapping as $oldId => $newUuid) {
                    DB::table($table)->where($column, (string) $oldId)->update([$column => $newUuid]);
                }
            }
        }

        // Recreate FK constraints
        foreach (self::PRODUCT_FK_TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                Schema::table($table, function (Blueprint $t) use ($table, $column) {
                    $isNullable = $column === 'related_product_id' || (isset(self::NULLABLE_PRODUCT_COLUMNS[$table]) && in_array($column, self::NULLABLE_PRODUCT_COLUMNS[$table], true));
                    $fk = $t->foreign($column)->references('id')->on('products');
                    if ($isNullable) {
                        $fk->nullOnDelete();
                    } else {
                        $fk->cascadeOnDelete();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('Rollback of products UUID migration is not supported. Restore from backup if needed.');
    }
};
