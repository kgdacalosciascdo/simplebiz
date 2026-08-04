<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_denominations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->string('denomination_type', 20)->default('NOTE');
            $table->decimal('face_value', 20, 6);
            $table->string('display_label', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('active')->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('system_standard')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'currency_id', 'status']);
        });

        Schema::create('cash_count_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->json('rules');
            $table->string('status', 32)->default('active')->index();
            $table->boolean('system_standard')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('cash_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('count_number', 80);
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignUuid('count_type_id')->constrained('cash_count_types')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('status', 32)->default('scheduled')->index();
            $table->date('count_date');
            $table->timestamp('cut_off_at');
            $table->timestamp('expected_as_of_at')->nullable();
            $table->timestamp('count_started_at')->nullable();
            $table->timestamp('count_completed_at')->nullable();
            $table->string('post_cutoff_policy', 32)->default('detect_and_review');
            $table->string('count_reason', 80)->nullable();
            $table->boolean('scheduled')->default(false);
            $table->foreignId('current_custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('counter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('witness_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('incoming_custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('outgoing_custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('expected_amount', 20, 6)->nullable();
            $table->timestamp('expected_snapshot_generated_at')->nullable();
            $table->string('expected_snapshot_hash', 128)->nullable();
            $table->unsignedInteger('expected_movement_count')->nullable();
            $table->unsignedInteger('post_cutoff_movement_count')->default(0);
            $table->json('post_cutoff_movement_ids')->nullable();
            $table->decimal('actual_amount', 20, 6)->nullable();
            $table->decimal('variance_amount', 20, 6)->nullable();
            $table->string('variance_classification', 32)->nullable();
            $table->boolean('recount_required')->default(false);
            $table->unsignedInteger('recount_count')->default(0);
            $table->foreignUuid('current_attempt_id')->nullable();
            $table->foreignUuid('variance_id')->nullable();
            $table->foreignUuid('adjustment_id')->nullable();
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->text('explanation')->nullable();
            $table->text('custodian_comments')->nullable();
            $table->text('witness_comments')->nullable();
            $table->text('reviewer_comments')->nullable();
            $table->text('approval_comments')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'count_number']);
            $table->index(['company_id', 'cash_account_id', 'status']);
            $table->index(['company_id', 'count_date', 'cut_off_at']);
            $table->index(['company_id', 'current_custodian_id', 'status']);
        });

        Schema::create('cash_count_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cash_count_id')->constrained('cash_counts')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('witnessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('actual_amount', 20, 6)->default(0);
            $table->string('status', 32)->default('draft')->index();
            $table->text('recount_reason')->nullable();
            $table->boolean('is_current')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['cash_count_id', 'attempt_number']);
            $table->index(['cash_count_id', 'is_current', 'status']);
        });

        Schema::create('cash_count_denomination_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attempt_id')->constrained('cash_count_attempts')->cascadeOnDelete();
            $table->foreignUuid('denomination_id')->constrained('cash_denominations')->restrictOnDelete();
            $table->decimal('face_value_snapshot', 20, 6);
            $table->string('label_snapshot', 120);
            $table->unsignedInteger('quantity');
            $table->decimal('line_amount', 20, 6);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['attempt_id', 'denomination_id']);
        });

        Schema::create('cash_count_non_denomination_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attempt_id')->constrained('cash_count_attempts')->cascadeOnDelete();
            $table->string('item_type', 40);
            $table->string('description', 180);
            $table->decimal('amount', 20, 6);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_count_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cash_count_id')->constrained('cash_counts')->cascadeOnDelete();
            $table->foreignUuid('attempt_id')->constrained('cash_count_attempts')->cascadeOnDelete();
            $table->string('confirmation_type', 32);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('confirmed');
            $table->text('comments')->nullable();
            $table->timestamp('confirmed_at');
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['attempt_id', 'confirmation_type']);
        });

        Schema::create('cash_count_variances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_count_id')->unique()->constrained('cash_counts')->cascadeOnDelete();
            $table->foreignUuid('accepted_attempt_id')->nullable()->constrained('cash_count_attempts')->restrictOnDelete();
            $table->decimal('expected_amount', 20, 6);
            $table->decimal('actual_amount', 20, 6);
            $table->decimal('variance_amount', 20, 6);
            $table->string('classification', 32);
            $table->decimal('tolerance_amount', 20, 6)->default(0);
            $table->boolean('within_tolerance')->default(false);
            $table->string('status', 32)->default('unresolved')->index();
            $table->string('disposition', 48)->nullable();
            $table->text('disposition_reason')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('adjustment_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'status', 'classification']);
        });

        Schema::create('cash_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('adjustment_number', 80);
            $table->foreignUuid('cash_count_id')->constrained('cash_counts')->restrictOnDelete();
            $table->foreignUuid('variance_id')->constrained('cash_count_variances')->restrictOnDelete();
            $table->string('status', 32)->default('prepared')->index();
            $table->string('direction', 16);
            $table->decimal('amount', 20, 6);
            $table->foreignUuid('offset_account_title_id')->nullable()->constrained('account_titles')->restrictOnDelete();
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->foreignUuid('cash_movement_document_id')->nullable()->constrained('cash_movement_documents')->restrictOnDelete();
            $table->foreignUuid('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('original_adjustment_id')->nullable();
            $table->uuid('reversal_adjustment_id')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reason');
            $table->text('reversal_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'adjustment_number']);
            $table->index(['company_id', 'cash_count_id', 'status']);
        });

        Schema::create('cash_custodian_handovers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('handover_number', 80);
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignUuid('cash_count_id')->nullable()->constrained('cash_counts')->restrictOnDelete();
            $table->foreignUuid('accepted_attempt_id')->nullable()->constrained('cash_count_attempts')->restrictOnDelete();
            $table->foreignId('outgoing_custodian_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('incoming_custodian_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('witness_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('draft')->index();
            $table->date('handover_date');
            $table->text('reason');
            $table->text('outgoing_comments')->nullable();
            $table->text('incoming_comments')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('outgoing_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('incoming_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('outgoing_confirmed_at')->nullable();
            $table->timestamp('incoming_confirmed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'handover_number']);
            $table->index(['company_id', 'cash_account_id', 'status']);
        });

        Schema::create('cash_count_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_count_id')->constrained('cash_counts')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['cash_count_id', 'created_at']);
        });

        Schema::table('cash_counts', function (Blueprint $table) {
            $table->foreign('current_attempt_id')->references('id')->on('cash_count_attempts')->nullOnDelete();
            $table->foreign('variance_id')->references('id')->on('cash_count_variances')->nullOnDelete();
            $table->foreign('adjustment_id')->references('id')->on('cash_adjustments')->nullOnDelete();
        });
        Schema::table('cash_count_variances', function (Blueprint $table) {
            $table->foreign('adjustment_id')->references('id')->on('cash_adjustments')->nullOnDelete();
        });
        Schema::table('cash_adjustments', function (Blueprint $table) {
            $table->foreign('original_adjustment_id')->references('id')->on('cash_adjustments')->restrictOnDelete();
            $table->foreign('reversal_adjustment_id')->references('id')->on('cash_adjustments')->restrictOnDelete();
        });
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('ALTER TABLE cash_denominations ADD CONSTRAINT cash_denominations_face_value_positive CHECK (face_value > 0)');
            Schema::getConnection()->statement('ALTER TABLE cash_count_denomination_lines ADD CONSTRAINT cash_count_denomination_lines_quantity_nonnegative CHECK (quantity >= 0)');
            Schema::getConnection()->statement('ALTER TABLE cash_count_denomination_lines ADD CONSTRAINT cash_count_denomination_lines_amount_nonnegative CHECK (line_amount >= 0)');
            Schema::getConnection()->statement('ALTER TABLE cash_count_non_denomination_lines ADD CONSTRAINT cash_count_non_denomination_lines_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_count_status_histories');
        Schema::dropIfExists('cash_custodian_handovers');
        Schema::dropIfExists('cash_adjustments');
        Schema::dropIfExists('cash_count_variances');
        Schema::dropIfExists('cash_count_confirmations');
        Schema::dropIfExists('cash_count_non_denomination_lines');
        Schema::dropIfExists('cash_count_denomination_lines');
        Schema::dropIfExists('cash_count_attempts');
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('cash_count_types');
        Schema::dropIfExists('cash_denominations');
    }
};
