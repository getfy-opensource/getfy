<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'recovery_email_stage')) {
                $table->unsignedTinyInteger('recovery_email_stage')->default(0)->after('metadata');
            }
            if (! Schema::hasColumn('orders', 'recovery_email_last_sent_at')) {
                $table->timestamp('recovery_email_last_sent_at')->nullable()->after('recovery_email_stage');
            }
            if (! Schema::hasColumn('orders', 'recovery_email_next_at')) {
                $table->timestamp('recovery_email_next_at')->nullable()->after('recovery_email_last_sent_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'recovery_email_next_at')) {
                $table->dropColumn('recovery_email_next_at');
            }
            if (Schema::hasColumn('orders', 'recovery_email_last_sent_at')) {
                $table->dropColumn('recovery_email_last_sent_at');
            }
            if (Schema::hasColumn('orders', 'recovery_email_stage')) {
                $table->dropColumn('recovery_email_stage');
            }
        });
    }
};

