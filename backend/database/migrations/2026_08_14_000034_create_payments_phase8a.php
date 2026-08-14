<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('request_number', 80);
            $table->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('requested_amount', 20, 6);
            $table->date('requested_payment_date');
            $table->text('reason')->nullable();
            $table->text('evidence_reference')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'request_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'supplier_id', 'status', 'requested_payment_date']);
        });

        Schema::create('payment_request_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payable_open_item_id')->constrained('payable_open_items')->restrictOnDelete();
            $table->string('source_document_number', 80);
            $table->decimal('proposed_amount', 20, 6);
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['payment_request_id', 'payable_open_item_id']);
            $table->index(['company_id', 'payable_open_item_id']);
        });

        Schema::create('payment_request_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('event_code', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'payment_request_id', 'created_at']);
        });

        Schema::create('payment_instructions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('payment_number', 80);
            $table->foreignUuid('payment_request_id')->nullable()->constrained('payment_requests')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->date('payment_date');
            $table->date('scheduled_date')->nullable();
            $table->decimal('gross_amount', 20, 6)->default(0);
            $table->decimal('discount_amount', 20, 6)->default(0);
            $table->decimal('withholding_amount', 20, 6)->default(0);
            $table->decimal('fee_amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('net_amount', 20, 6)->default(0);
            $table->decimal('confirmed_amount', 20, 6)->default(0);
            $table->decimal('allocated_amount', 20, 6)->default(0);
            $table->decimal('unapplied_amount', 20, 6)->default(0);
            $table->text('reference')->nullable();
            $table->text('remittance_details')->nullable();
            $table->text('evidence_reference')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('execution_state', 32)->default('not_started');
            $table->string('confirmation_state', 32)->default('not_confirmed');
            $table->string('allocation_state', 32)->default('not_allocated');
            $table->string('instrument_type', 32)->nullable();
            $table->string('external_reference', 180)->nullable();
            $table->string('failure_reason', 2000)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('submitted_version')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'payment_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'supplier_id', 'status', 'payment_date']);
            $table->index(['company_id', 'cash_account_id', 'currency_id', 'status']);
            $table->index(['company_id', 'external_reference']);
        });

        Schema::create('payment_instruction_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payable_open_item_id')->constrained('payable_open_items')->restrictOnDelete();
            $table->string('source_document_number', 80);
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('requested_amount', 20, 6);
            $table->decimal('allocated_amount', 20, 6)->default(0);
            $table->timestamps();
            $table->unique(['payment_instruction_id', 'payable_open_item_id']);
            $table->index(['company_id', 'payable_open_item_id']);
        });

        Schema::create('payment_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('action', 32)->default('submitted');
            $table->unsignedInteger('submitted_version');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('authority_context')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('payment_instruments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->string('instrument_type', 32);
            $table->string('status', 32)->default('reserved');
            $table->string('check_number', 80)->nullable();
            $table->string('masked_reference', 180)->nullable();
            $table->string('external_reference', 180)->nullable();
            $table->string('print_status', 24)->nullable();
            $table->string('release_status', 24)->default('not_released');
            $table->text('evidence_reference')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'payment_instruction_id', 'instrument_type']);
            $table->unique(['company_id', 'cash_account_id', 'check_number']);
            $table->index(['company_id', 'status', 'instrument_type']);
        });

        Schema::create('payment_execution_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->cascadeOnDelete();
            $table->foreignUuid('payment_instrument_id')->nullable()->constrained('payment_instruments')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('channel', 32);
            $table->string('status', 32)->default('queued');
            $table->string('external_reference', 180)->nullable();
            $table->string('request_hash', 128)->nullable();
            $table->string('response_code', 80)->nullable();
            $table->text('response_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['payment_instruction_id', 'attempt_number']);
            $table->index(['company_id', 'status', 'channel']);
        });

        Schema::create('payment_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->cascadeOnDelete();
            $table->foreignUuid('payment_execution_attempt_id')->nullable()->constrained('payment_execution_attempts')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24);
            $table->decimal('confirmed_amount', 20, 6)->default(0);
            $table->date('confirmed_date')->nullable();
            $table->string('external_reference', 180)->nullable();
            $table->text('evidence_reference')->nullable();
            $table->text('reason')->nullable();
            $table->boolean('manual_confirmation')->default(false);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'status', 'confirmed_date']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->cascadeOnDelete();
            $table->foreignUuid('payment_confirmation_id')->constrained('payment_confirmations')->restrictOnDelete();
            $table->foreignUuid('payable_open_item_id')->constrained('payable_open_items')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('amount', 20, 6);
            $table->date('allocation_date');
            $table->string('status', 24)->default('applied');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->uuid('reversal_allocation_id')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'payable_open_item_id', 'status']);
            $table->index(['company_id', 'payment_instruction_id', 'status']);
        });

        Schema::create('payment_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('event_code', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'payment_instruction_id', 'created_at']);
        });

        Schema::create('remittance_advices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('advice_number', 80);
            $table->string('status', 24)->default('ready');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'advice_number']);
            $table->unique(['company_id', 'payment_instruction_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_requests ADD CONSTRAINT payment_requests_status_check CHECK (status IN ('draft','submitted','returned','approved','rejected','cancelled','converted','expired'))");
            DB::statement("ALTER TABLE payment_instructions ADD CONSTRAINT payment_instructions_status_check CHECK (status IN ('draft','pending_approval','approved','scheduled','ready','released','pending_confirmation','confirmed','partially_allocated','allocated','failed','rejected','cancelled','voided','reversed'))");
            DB::statement('ALTER TABLE payment_instructions ADD CONSTRAINT payment_instructions_amounts_check CHECK (gross_amount >= 0 AND discount_amount >= 0 AND withholding_amount >= 0 AND fee_amount >= 0 AND tax_amount >= 0 AND net_amount >= 0 AND confirmed_amount >= 0 AND allocated_amount >= 0 AND unapplied_amount >= 0)');
            DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE payment_confirmations ADD CONSTRAINT payment_confirmations_status_check CHECK (status IN ('confirmed','pending','failed','rejected'))");
        }

        $permissions = [
            'payments.view' => 'View Payments', 'payments.history' => 'View Payment History', 'payments.workbench.view' => 'View Payment Workbench',
            'payments.requests.create' => 'Create Payment Requests', 'payments.requests.update' => 'Update Payment Requests', 'payments.requests.submit' => 'Submit Payment Requests',
            'payments.create' => 'Create Payment Instructions', 'payments.update' => 'Update Payment Drafts', 'payments.submit' => 'Submit Payments',
            'payments.review' => 'Review Payments', 'payments.approve' => 'Approve Payments', 'payments.reject' => 'Reject or Return Payments',
            'payments.schedule' => 'Schedule Payments', 'payments.release' => 'Release Payments', 'payments.cash.confirm' => 'Confirm Cash Payments',
            'payments.electronic.confirm' => 'Confirm Electronic Payments', 'payments.manual-confirm' => 'Manually Confirm Payments',
            'payments.pending' => 'Record Pending Payments', 'payments.failure' => 'Record Payment Failures', 'payments.allocate' => 'Allocate Payments',
            'payments.check.reserve' => 'Reserve Checks', 'payments.check.print' => 'Print Checks', 'payments.check.release' => 'Release Checks',
            'payments.evidence.upload' => 'Upload Payment Evidence', 'payments.print' => 'Print Payment Documents', 'payments.remittance.view' => 'View Remittance Advice',
        ];
        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'payments', 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        $memberIds = DB::table('permissions')->whereIn('key', ['payments.view', 'payments.history', 'payments.workbench.view'])->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach ($memberIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_advices');
        Schema::dropIfExists('payment_status_histories');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_confirmations');
        Schema::dropIfExists('payment_execution_attempts');
        Schema::dropIfExists('payment_instruments');
        Schema::dropIfExists('payment_approvals');
        Schema::dropIfExists('payment_instruction_sources');
        Schema::dropIfExists('payment_instructions');
        Schema::dropIfExists('payment_request_status_histories');
        Schema::dropIfExists('payment_request_sources');
        Schema::dropIfExists('payment_requests');
        $ids = DB::table('permissions')->where('module', 'payments')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
