<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->uuid('opening_balance_offset_account_title_id')->nullable()->index();
            $table->date('opening_balance_lock_date')->nullable();
        });

        Schema::create('cash_account_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('classification', 20);
            $table->json('default_capabilities');
            $table->json('allowed_capabilities');
            $table->boolean('requires_custodian')->default(false);
            $table->boolean('supports_cash_count')->default(false);
            $table->boolean('supports_reconciliation')->default(false);
            $table->boolean('supports_statement_import')->default(false);
            $table->boolean('supports_check')->default(false);
            $table->boolean('system_standard')->default(true);
            $table->boolean('locked')->default(true);
            $table->string('status', 32)->default('active')->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cash_account_institutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider_type', 32);
            $table->string('name', 160);
            $table->string('branch_name', 160)->nullable();
            $table->string('routing_reference', 120)->nullable();
            $table->string('statement_format', 40)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'name']);
        });

        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('display_name', 180)->nullable();
            $table->foreignUuid('cash_account_type_id')->constrained('cash_account_types')->restrictOnDelete();
            $table->foreignUuid('account_title_id')->constrained('account_titles')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('institution_id')->nullable()->constrained('cash_account_institutions')->restrictOnDelete();
            $table->text('account_identifier_encrypted')->nullable();
            $table->string('account_identifier_last4', 4)->nullable();
            $table->string('masked_account_identifier', 32)->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->string('account_subtype', 80)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->text('status_reason')->nullable();
            $table->json('restricted_capabilities')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('restricted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restricted_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('reactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reactivated_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_count_at')->nullable();
            $table->timestamp('last_reconciliation_at')->nullable();
            $table->string('source_channel', 40)->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'cash_account_type_id', 'status']);
            $table->index(['company_id', 'currency_id', 'branch_id']);
        });

        Schema::create('cash_account_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->cascadeOnDelete();
            $table->string('capability', 40);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cash_account_id', 'capability']);
        });

        Schema::create('cash_account_custodians', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('responsibility_type', 40)->default('custodian');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'cash_account_id', 'status']);
        });

        Schema::create('business_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_type', 80);
            $table->string('status', 32)->default('posted');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('business_date');
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_key', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
        });

        Schema::create('accounting_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_transaction_id')->constrained('business_transactions')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_type', 80);
            $table->string('status', 32)->default('posted');
            $table->date('business_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique('business_transaction_id');
        });

        Schema::create('accounting_transaction_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('accounting_transaction_id')->constrained('accounting_transactions')->cascadeOnDelete();
            $table->foreignUuid('account_title_id')->constrained('account_titles')->restrictOnDelete();
            $table->decimal('debit', 20, 6)->default(0);
            $table->decimal('credit', 20, 6)->default(0);
            $table->string('currency_code', 3);
            $table->string('description', 180)->nullable();
            $table->timestamps();
            $table->index(['accounting_transaction_id', 'account_title_id']);
        });

        Schema::create('opening_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->date('effective_date');
            $table->string('direction', 16)->default('increase');
            $table->decimal('amount', 20, 6);
            $table->string('opening_source', 80);
            $table->string('migration_reference', 160)->nullable();
            $table->foreignUuid('offset_account_title_id')->nullable()->constrained('account_titles')->restrictOnDelete();
            $table->foreignUuid('reason_code_id')->constrained('reason_codes')->restrictOnDelete();
            $table->text('explanation');
            $table->string('batch_reference', 120)->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignUuid('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->uuid('cash_movement_id')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'cash_account_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('direction', 16);
            $table->decimal('amount', 20, 6);
            $table->string('currency_code', 3);
            $table->date('business_date');
            $table->timestamp('posted_at')->nullable();
            $table->string('source_event_type', 80);
            $table->string('source_record_type', 120);
            $table->string('source_record_id', 120);
            $table->string('source_reference', 160)->nullable();
            $table->string('movement_status', 32)->default('draft')->index();
            $table->string('clearing_status', 32)->default('not_applicable');
            $table->string('reconciliation_status', 32)->default('not_applicable');
            $table->uuid('original_movement_id')->nullable();
            $table->uuid('reversal_movement_id')->nullable();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'cash_account_id', 'business_date']);
            $table->index(['source_record_type', 'source_record_id']);
        });

        Schema::table('opening_balances', function (Blueprint $table) {
            $table->foreign('cash_movement_id')->references('id')->on('cash_movements')->restrictOnDelete();
        });
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreign('original_movement_id')->references('id')->on('cash_movements')->restrictOnDelete();
            $table->foreign('reversal_movement_id')->references('id')->on('cash_movements')->restrictOnDelete();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('owner_module', 80);
            $table->string('record_type', 160);
            $table->string('record_id', 120);
            $table->string('original_filename', 255);
            $table->string('stored_path', 500);
            $table->string('disk', 80)->default('local');
            $table->string('mime_type', 160);
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 128);
            $table->string('sensitivity', 32)->default('confidential');
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'record_type', 'record_id']);
            $table->unique(['company_id', 'record_type', 'record_id', 'file_hash']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cash_account_types ADD CONSTRAINT cash_account_types_classification_check CHECK (classification IN ('physical','non_physical'))");
            DB::statement("ALTER TABLE cash_accounts ADD CONSTRAINT cash_accounts_status_check CHECK (status IN ('draft','active','restricted','inactive'))");
            DB::statement('ALTER TABLE cash_account_custodians ADD CONSTRAINT cash_account_custodians_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE opening_balances ADD CONSTRAINT opening_balances_amount_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE opening_balances ADD CONSTRAINT opening_balances_direction_check CHECK (direction IN ('increase','decrease'))");
            DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_direction_check CHECK (direction IN ('increase','decrease'))");
            DB::statement("CREATE UNIQUE INDEX opening_balances_one_active_idx ON opening_balances (cash_account_id) WHERE status IN ('draft','submitted','approved','posted')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('opening_balances');
        Schema::dropIfExists('accounting_transaction_lines');
        Schema::dropIfExists('accounting_transactions');
        Schema::dropIfExists('business_transactions');
        Schema::dropIfExists('cash_account_custodians');
        Schema::dropIfExists('cash_account_capabilities');
        Schema::dropIfExists('cash_accounts');
        Schema::dropIfExists('cash_account_institutions');
        Schema::dropIfExists('cash_account_types');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['opening_balance_offset_account_title_id', 'opening_balance_lock_date']);
        });
    }
};
