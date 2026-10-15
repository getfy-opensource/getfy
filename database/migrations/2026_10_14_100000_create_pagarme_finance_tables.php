<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro Pagar.me: conta (recebedor + chave financeira, ligada a 1+ tenants) e espelho local de
 * recebíveis, transferências, antecipações e liquidações (a API v5 não tem webhook para esses objetos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pagarme_accounts')) {
            Schema::create('pagarme_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('label', 120);
                $table->string('recipient_id', 64)->unique();
                $table->text('finance_secret_key');
                $table->timestamp('payables_synced_until')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->string('last_sync_error', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pagarme_account_tenant')) {
            Schema::create('pagarme_account_tenant', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pagarme_account_id')->constrained('pagarme_accounts')->cascadeOnDelete();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pagarme_payables')) {
            Schema::create('pagarme_payables', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pagarme_account_id')->constrained('pagarme_accounts')->cascadeOnDelete();
                $table->string('payable_id', 64)->unique();
                $table->string('charge_id', 64)->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('type', 32);
                $table->string('status', 32);
                $table->string('payment_method', 32)->nullable();
                $table->unsignedSmallInteger('installment')->nullable();
                $table->bigInteger('amount_cents');
                $table->bigInteger('fee_cents')->default(0);
                $table->bigInteger('anticipation_fee_cents')->default(0);
                $table->bigInteger('fraud_coverage_fee_cents')->default(0);
                $table->date('payment_date')->nullable();
                $table->timestamp('accrual_at')->nullable();
                $table->string('settlement_id', 64)->nullable();
                $table->timestamp('gateway_created_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'payment_date'], 'pagarme_payables_tenant_date_idx');
                $table->index(['pagarme_account_id', 'status', 'payment_date'], 'pagarme_payables_account_status_idx');
            });
        }

        if (! Schema::hasTable('pagarme_transfers')) {
            Schema::create('pagarme_transfers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pagarme_account_id')->constrained('pagarme_accounts')->cascadeOnDelete();
                $table->string('transfer_id', 64)->nullable()->unique();
                $table->string('idempotency_key', 120)->nullable()->unique();
                $table->bigInteger('amount_cents');
                $table->bigInteger('fee_cents')->nullable();
                $table->string('status', 32);
                $table->string('type', 32)->nullable();
                $table->timestamp('funding_estimated_at')->nullable();
                $table->json('bank_account_masked')->nullable();
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
                $table->string('requested_ip', 45)->nullable();
                $table->string('error_message', 255)->nullable();
                $table->timestamp('gateway_created_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pagarme_anticipations')) {
            Schema::create('pagarme_anticipations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pagarme_account_id')->constrained('pagarme_accounts')->cascadeOnDelete();
                $table->string('anticipation_id', 64)->nullable()->unique();
                $table->string('idempotency_key', 120)->nullable()->unique();
                $table->bigInteger('requested_amount_cents');
                $table->bigInteger('amount_cents')->nullable();
                $table->bigInteger('fee_cents')->nullable();
                $table->bigInteger('anticipation_fee_cents')->nullable();
                $table->string('status', 32);
                $table->string('timeframe', 8)->nullable();
                $table->date('payment_date')->nullable();
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
                $table->string('requested_ip', 45)->nullable();
                $table->string('error_message', 255)->nullable();
                $table->timestamp('gateway_created_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pagarme_settlements')) {
            Schema::create('pagarme_settlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pagarme_account_id')->constrained('pagarme_accounts')->cascadeOnDelete();
                $table->string('settlement_id', 64)->unique();
                $table->bigInteger('amount_cents');
                $table->string('product', 32)->nullable();
                $table->string('card_brand', 32)->nullable();
                $table->string('status', 32)->nullable();
                $table->date('payment_date')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pagarme_settlements');
        Schema::dropIfExists('pagarme_anticipations');
        Schema::dropIfExists('pagarme_transfers');
        Schema::dropIfExists('pagarme_payables');
        Schema::dropIfExists('pagarme_account_tenant');
        Schema::dropIfExists('pagarme_accounts');
    }
};
