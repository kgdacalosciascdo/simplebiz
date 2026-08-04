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
            $table->boolean('allow_negative_cash_balance')->default(false);
            $table->boolean('negative_balance_requires_approval')->default(true);
            $table->date('cash_movement_lock_date')->nullable();
        });

        Schema::create('cash_document_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('sequence_key', 40);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'sequence_key']);
        });

        Schema::create('cash_movement_purposes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 48)->unique();
            $table->string('name', 120);
            $table->string('document_kind', 24);
            $table->string('direction', 16)->nullable();
            $table->string('required_capability', 48)->nullable();
            $table->boolean('requires_destination')->default(false);
            $table->boolean('requires_payment_method')->default(false);
            $table->boolean('requires_evidence')->default(true);
            $table->string('reason_domain', 48);
            $table->string('clearing_mode', 24)->default('not_applicable');
            $table->boolean('approval_required')->default(true);
            $table->boolean('reversal_allowed')->default(true);
            $table->string('owning_module', 64)->default('cash-accounts');
            $table->json('allowed_source_types');
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('cash_movement_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_number', 40);
            $table->foreignUuid('purpose_id')->constrained('cash_movement_purposes')->restrictOnDelete();
            $table->string('source_type', 64);
            $table->string('source_record_type', 160)->nullable();
            $table->string('source_record_id', 120)->nullable();
            $table->string('external_reference', 160)->nullable();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->string('direction', 16);
            $table->decimal('amount', 20, 6);
            $table->date('business_date');
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('offset_account_title_id')->nullable()->constrained('account_titles')->restrictOnDelete();
            $table->foreignUuid('reason_code_id')->constrained('reason_codes')->restrictOnDelete();
            $table->text('explanation');
            $table->text('supporting_reference')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('submitted_version')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->boolean('negative_balance_override')->default(false);
            $table->text('negative_balance_reason')->nullable();
            $table->foreignUuid('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('original_document_id')->nullable();
            $table->uuid('reversal_document_id')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'document_number']);
            $table->index(['company_id', 'cash_account_id', 'business_date']);
            $table->index(['company_id', 'status', 'business_date']);
            $table->index(['company_id', 'external_reference']);
            $table->index(['source_record_type', 'source_record_id']);
        });

        Schema::create('cash_transfer_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_number', 40);
            $table->string('purpose', 32);
            $table->foreignUuid('source_cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignUuid('destination_cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('amount', 20, 6);
            $table->date('business_date');
            $table->date('expected_completion_date')->nullable();
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('reason_code_id')->constrained('reason_codes')->restrictOnDelete();
            $table->string('external_reference', 160)->nullable();
            $table->text('explanation');
            $table->text('supporting_reference')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('submitted_version')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignUuid('source_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignUuid('destination_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('original_document_id')->nullable();
            $table->uuid('reversal_document_id')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'document_number']);
            $table->index(['company_id', 'status', 'business_date']);
            $table->index(['company_id', 'source_cash_account_id', 'destination_cash_account_id']);
            $table->index(['company_id', 'external_reference']);
        });

        Schema::create('cash_transfer_legs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_document_id')->constrained('cash_transfer_documents')->cascadeOnDelete();
            $table->foreignUuid('cash_movement_id')->constrained('cash_movements')->restrictOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('direction', 16);
            $table->timestamps();
            $table->unique(['transfer_document_id', 'cash_movement_id']);
            $table->unique(['transfer_document_id', 'direction']);
        });

        Schema::create('cash_movement_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->uuid('document_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'document_type', 'document_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_movement_documents ADD CONSTRAINT cash_movement_documents_amount_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE cash_movement_documents ADD CONSTRAINT cash_movement_documents_direction_check CHECK (direction IN ('increase','decrease'))");
            DB::statement('ALTER TABLE cash_transfer_documents ADD CONSTRAINT cash_transfer_documents_amount_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE cash_transfer_documents ADD CONSTRAINT cash_transfer_documents_accounts_check CHECK (source_cash_account_id <> destination_cash_account_id)');
        }

        Schema::table('cash_movement_documents', function (Blueprint $table) {
            $table->foreign('original_document_id')->references('id')->on('cash_movement_documents')->restrictOnDelete();
            $table->foreign('reversal_document_id')->references('id')->on('cash_movement_documents')->restrictOnDelete();
        });
        Schema::table('cash_transfer_documents', function (Blueprint $table) {
            $table->foreign('original_document_id')->references('id')->on('cash_transfer_documents')->restrictOnDelete();
            $table->foreign('reversal_document_id')->references('id')->on('cash_transfer_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movement_status_histories');
        Schema::dropIfExists('cash_transfer_legs');
        Schema::dropIfExists('cash_transfer_documents');
        Schema::dropIfExists('cash_movement_documents');
        Schema::dropIfExists('cash_movement_purposes');
        Schema::dropIfExists('cash_document_sequences');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['allow_negative_cash_balance', 'negative_balance_requires_approval', 'cash_movement_lock_date']);
        });
    }
};
