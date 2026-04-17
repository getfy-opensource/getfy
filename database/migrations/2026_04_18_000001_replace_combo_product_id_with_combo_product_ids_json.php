<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'combo_product_ids')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('combo_product_ids')->nullable()->after('member_area_config');
            });
        }

        if (Schema::hasTable('product_offers') && ! Schema::hasColumn('product_offers', 'combo_product_ids')) {
            Schema::table('product_offers', function (Blueprint $table) {
                $table->json('combo_product_ids')->nullable()->after('product_id');
            });
        }

        if (Schema::hasTable('subscription_plans') && ! Schema::hasColumn('subscription_plans', 'combo_product_ids')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->json('combo_product_ids')->nullable()->after('product_id');
            });
        }

        $this->migrateSingleIdToJsonArray('products');
        $this->migrateSingleIdToJsonArray('product_offers');
        $this->migrateSingleIdToJsonArray('subscription_plans');

        if (Schema::hasColumn('products', 'combo_product_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['combo_product_id']);
                $table->dropColumn('combo_product_id');
            });
        }
        if (Schema::hasColumn('product_offers', 'combo_product_id')) {
            Schema::table('product_offers', function (Blueprint $table) {
                $table->dropForeign(['combo_product_id']);
                $table->dropColumn('combo_product_id');
            });
        }
        if (Schema::hasColumn('subscription_plans', 'combo_product_id')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->dropForeign(['combo_product_id']);
                $table->dropColumn('combo_product_id');
            });
        }
    }

    private function migrateSingleIdToJsonArray(string $table): void
    {
        if (! Schema::hasColumn($table, 'combo_product_id') || ! Schema::hasColumn($table, 'combo_product_ids')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $query = DB::table($table)->whereNotNull('combo_product_id');

        if ($driver === 'sqlite') {
            foreach ($query->get() as $row) {
                $pk = $row->id;
                $json = json_encode([(string) $row->combo_product_id]);
                DB::table($table)->where('id', $pk)->update(['combo_product_ids' => $json]);
            }

            return;
        }

        DB::table($table)->whereNotNull('combo_product_id')->orderBy('id')->chunk(200, function ($rows) use ($table) {
            foreach ($rows as $row) {
                $json = json_encode([(string) $row->combo_product_id]);
                DB::table($table)->where('id', $row->id)->update(['combo_product_ids' => $json]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'combo_product_ids')) {
            return;
        }

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

        foreach (['products', 'product_offers', 'subscription_plans'] as $tableName) {
            $rows = DB::table($tableName)->whereNotNull('combo_product_ids')->get();
            foreach ($rows as $row) {
                $decoded = json_decode((string) $row->combo_product_ids, true);
                $first = is_array($decoded) && isset($decoded[0]) ? $decoded[0] : null;
                DB::table($tableName)->where('id', $row->id)->update([
                    'combo_product_id' => $first,
                ]);
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('combo_product_ids');
        });
        Schema::table('product_offers', function (Blueprint $table) {
            $table->dropColumn('combo_product_ids');
        });
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('combo_product_ids');
        });
    }
};
