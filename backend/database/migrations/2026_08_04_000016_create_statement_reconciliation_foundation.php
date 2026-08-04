<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('batch_number', 40);
            $table->string('provider_reference', 160)->nullable();
            $table->string('statement_account_identifier_snapshot', 180)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency_code', 3);
            $table->string('status', 32)->default('uploaded')->index();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->uuid('attachment_id')->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('file_hash', 128)->nullable();
            $table->string('file_format', 40)->default('manual');
            $table->string('parser_version', 40)->default('manual-v1');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->decimal('opening_statement_balance', 20, 6)->nullable();
            $table->decimal('closing_statement_balance', 20, 6)->nullable();
            $table->decimal('total_debit', 20, 6)->default(0);
            $table->decimal('total_credit', 20, 6)->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedInteger('parsed_line_count')->default(0);
            $table->unsignedInteger('rejected_line_count')->default(0);
            $table->unsignedInteger('duplicate_line_count')->default(0);
            $table->json('validation_summary')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'batch_number']);
            $table->index(['company_id', 'cash_account_id', 'period_start', 'period_end']);
            $table->index(['company_id', 'cash_account_id', 'file_hash']);
        });

        Schema::create('statement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('statement_import_batch_id')->constrained('statement_import_batches')->restrictOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->string('external_line_id', 160)->nullable();
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->date('posting_date')->nullable();
            $table->text('description');
            $table->string('reference', 180)->nullable();
            $table->string('counterparty_name', 180)->nullable();
            $table->string('external_account_reference', 180)->nullable();
            $table->decimal('debit_amount', 20, 6)->default(0);
            $table->decimal('credit_amount', 20, 6)->default(0);
            $table->decimal('signed_amount', 20, 6);
            $table->string('currency_code', 3);
            $table->decimal('running_balance', 20, 6)->nullable();
            $table->string('check_reference', 120)->nullable();
            $table->string('provider_transaction_type', 80)->nullable();
            $table->string('normalized_transaction_type', 80)->default('other');
            $table->json('raw_snapshot')->nullable();
            $table->string('normalization_version', 40)->default('manual-v1');
            $table->string('fingerprint', 128);
            $table->string('validation_status', 32)->default('pending')->index();
            $table->json('validation_errors')->nullable();
            $table->string('match_status', 32)->default('unmatched')->index();
            $table->string('reconciliation_status', 32)->default('unreconciled')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['statement_import_batch_id', 'source_row_number']);
            $table->index(['company_id', 'cash_account_id', 'transaction_date']);
            $table->index(['statement_import_batch_id', 'fingerprint']);
            $table->index(['company_id', 'external_line_id']);
        });

        Schema::create('reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reconciliation_number', 40);
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->foreignUuid('statement_import_batch_id')->constrained('statement_import_batches')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->decimal('statement_opening_balance', 20, 6)->default(0);
            $table->decimal('statement_closing_balance', 20, 6)->default(0);
            $table->decimal('statement_inflows', 20, 6)->default(0);
            $table->decimal('statement_outflows', 20, 6)->default(0);
            $table->decimal('internal_opening_balance', 20, 6)->default(0);
            $table->decimal('internal_closing_balance', 20, 6)->default(0);
            $table->decimal('internal_inflows', 20, 6)->default(0);
            $table->decimal('internal_outflows', 20, 6)->default(0);
            $table->decimal('matched_statement_amount', 20, 6)->default(0);
            $table->decimal('matched_movement_amount', 20, 6)->default(0);
            $table->decimal('outstanding_statement_amount', 20, 6)->default(0);
            $table->decimal('outstanding_movement_amount', 20, 6)->default(0);
            $table->decimal('difference_amount', 20, 6)->default(0);
            $table->decimal('adjustment_amount', 20, 6)->default(0);
            $table->string('population_fingerprint', 128)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'reconciliation_number']);
            $table->index(['company_id', 'cash_account_id', 'period_start', 'period_end']);
            $table->index(['company_id', 'status', 'completed_at']);
        });

        Schema::create('reconciliation_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reconciliation_id')->constrained('reconciliations')->restrictOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('status', 32)->default('confirmed')->index();
            $table->string('method', 32);
            $table->decimal('tolerance_amount', 20, 6)->default(0);
            $table->decimal('difference_amount', 20, 6)->default(0);
            $table->text('reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unmatched_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'reconciliation_id', 'status']);
        });

        Schema::create('reconciliation_match_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reconciliation_id')->constrained('reconciliations')->restrictOnDelete();
            $table->foreignUuid('reconciliation_match_id')->constrained('reconciliation_matches')->restrictOnDelete();
            $table->foreignUuid('statement_line_id')->nullable()->constrained('statement_lines')->restrictOnDelete();
            $table->foreignUuid('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->decimal('amount', 20, 6);
            $table->timestamps();
            $table->index(['company_id', 'statement_line_id']);
            $table->index(['company_id', 'cash_movement_id']);
        });

        Schema::create('reconciliation_outstanding_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reconciliation_id')->constrained('reconciliations')->restrictOnDelete();
            $table->string('source_type', 32);
            $table->foreignUuid('statement_line_id')->nullable()->constrained('statement_lines')->restrictOnDelete();
            $table->foreignUuid('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->string('classification', 48);
            $table->string('status', 32)->default('open')->index();
            $table->decimal('amount', 20, 6);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['company_id', 'reconciliation_id', 'status']);
            $table->index(['company_id', 'source_type', 'classification']);
        });

        Schema::create('reconciliation_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reconciliation_id')->constrained('reconciliations')->restrictOnDelete();
            $table->foreignUuid('outstanding_item_id')->nullable()->constrained('reconciliation_outstanding_items')->restrictOnDelete();
            $table->string('adjustment_number', 40);
            $table->string('direction', 16);
            $table->decimal('amount', 20, 6);
            $table->foreignUuid('offset_account_title_id')->constrained('account_titles')->restrictOnDelete();
            $table->foreignUuid('reason_code_id')->constrained('reason_codes')->restrictOnDelete();
            $table->text('explanation');
            $table->string('status', 32)->default('draft')->index();
            $table->foreignUuid('cash_movement_document_id')->nullable()->constrained('cash_movement_documents')->restrictOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'adjustment_number']);
        });

        Schema::create('reconciliation_completions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reconciliation_id')->constrained('reconciliations')->restrictOnDelete();
            $table->unsignedInteger('reconciliation_version');
            $table->string('status', 32)->default('completed');
            $table->decimal('difference_amount', 20, 6)->default(0);
            $table->string('population_fingerprint', 128);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at');
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['reconciliation_id', 'reconciliation_version']);
        });

        Schema::create('reconciliation_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reconciliation_id')->constrained('reconciliations')->restrictOnDelete();
            $table->string('event', 48);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->unsignedInteger('version');
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'reconciliation_id', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('ALTER TABLE statement_import_batches ADD CONSTRAINT statement_import_batches_period_check CHECK (period_end >= period_start)');
            Schema::getConnection()->statement("ALTER TABLE statement_import_batches ADD CONSTRAINT statement_import_batches_status_check CHECK (status IN ('uploaded','processing','validated','validation_failed','ready','cancelled','reconciled'))");
            Schema::getConnection()->statement('ALTER TABLE statement_lines ADD CONSTRAINT statement_lines_amount_check CHECK (debit_amount >= 0 AND credit_amount >= 0 AND NOT (debit_amount > 0 AND credit_amount > 0) AND signed_amount = credit_amount - debit_amount)');
            Schema::getConnection()->statement("ALTER TABLE statement_lines ADD CONSTRAINT statement_lines_validation_check CHECK (validation_status IN ('pending','valid','invalid','duplicate'))");
            Schema::getConnection()->statement('ALTER TABLE reconciliations ADD CONSTRAINT reconciliations_period_check CHECK (period_end >= period_start)');
            Schema::getConnection()->statement('ALTER TABLE reconciliation_match_allocations ADD CONSTRAINT reconciliation_match_allocations_amount_check CHECK (amount > 0 AND (statement_line_id IS NOT NULL OR cash_movement_id IS NOT NULL))');
            Schema::getConnection()->statement("ALTER TABLE reconciliation_outstanding_items ADD CONSTRAINT reconciliation_outstanding_items_source_check CHECK ((source_type = 'statement_line' AND statement_line_id IS NOT NULL AND cash_movement_id IS NULL) OR (source_type = 'cash_movement' AND cash_movement_id IS NOT NULL AND statement_line_id IS NULL))");
            Schema::getConnection()->statement('CREATE UNIQUE INDEX statement_import_batches_exact_file_idx ON statement_import_batches (company_id, cash_account_id, period_start, period_end, file_hash) WHERE file_hash IS NOT NULL');
            Schema::getConnection()->statement("CREATE UNIQUE INDEX reconciliation_one_active_batch_idx ON reconciliations (statement_import_batch_id) WHERE status NOT IN ('completed','cancelled')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_history');
        Schema::dropIfExists('reconciliation_completions');
        Schema::dropIfExists('reconciliation_adjustments');
        Schema::dropIfExists('reconciliation_outstanding_items');
        Schema::dropIfExists('reconciliation_match_allocations');
        Schema::dropIfExists('reconciliation_matches');
        Schema::dropIfExists('reconciliations');
        Schema::dropIfExists('statement_lines');
        Schema::dropIfExists('statement_import_batches');
    }
};
