<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return;
        }

        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('checkout_sessions', 'recovery_email_stage')) {
                $table->unsignedTinyInteger('recovery_email_stage')->default(0)->after('abandoned_webhook_fired_at');
            }
            if (! Schema::hasColumn('checkout_sessions', 'recovery_email_last_sent_at')) {
                $table->timestamp('recovery_email_last_sent_at')->nullable()->after('recovery_email_stage');
            }
            if (! Schema::hasColumn('checkout_sessions', 'recovery_email_next_at')) {
                $table->timestamp('recovery_email_next_at')->nullable()->after('recovery_email_last_sent_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return;
        }

        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_sessions', 'recovery_email_next_at')) {
                $table->dropColumn('recovery_email_next_at');
            }
            if (Schema::hasColumn('checkout_sessions', 'recovery_email_last_sent_at')) {
                $table->dropColumn('recovery_email_last_sent_at');
            }
            if (Schema::hasColumn('checkout_sessions', 'recovery_email_stage')) {
                $table->dropColumn('recovery_email_stage');
            }
        });
    }
};

