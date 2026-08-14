<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_instructions', function (Blueprint $table) {
            $table->string('source_kind', 40)->default('supplier_payable')->after('payment_request_id');
            $table->uuid('reversal_correction_id')->nullable()->after('reversal_reason');
            $table->index(['company_id', 'source_kind', 'status']);
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->uuid('parent_allocation_id')->nullable()->after('reversal_allocation_id');
            $table->uuid('payment_advance_id')->nullable()->after('parent_allocation_id');
            $table->string('correction_type', 24)->default('original')->after('status');
            $table->text('correction_reason')->nullable()->after('correction_type');
            $table->index(['company_id', 'parent_allocation_id', 'correction_type']);
        });

        Schema::table('payment_instruments', function (Blueprint $table) {
            $table->timestamp('printed_at')->nullable()->after('print_status');
            $table->foreignId('printed_by')->nullable()->after('printed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable()->after('printed_by');
            $table->foreignId('signed_by')->nullable()->after('signed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('stopped_at')->nullable()->after('signed_by');
            $table->foreignId('stopped_by')->nullable()->after('stopped_at')->constrained('users')->nullOnDelete();
            $table->text('stop_reason')->nullable()->after('stopped_by');
            $table->text('stop_evidence_reference')->nullable()->after('stop_reason');
            $table->timestamp('voided_at')->nullable()->after('stop_evidence_reference');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable()->after('voided_by');
            $table->timestamp('stale_at')->nullable()->after('void_reason');
            $table->uuid('replaces_instrument_id')->nullable()->after('stale_at');
            $table->uuid('replaced_by_instrument_id')->nullable()->after('replaces_instrument_id');
            $table->index(['company_id', 'instrument_type', 'status']);
        });

        Schema::create('payment_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('original_payment_id')->constrained('payment_instructions')->restrictOnDelete();
            $table->string('correction_number', 80);
            $table->string('correction_type', 32);
            $table->string('status', 24)->default('completed');
            $table->string('original_status', 32);
            $table->decimal('amount', 20, 6)->default(0);
            $table->text('reason');
            $table->text('evidence_reference')->nullable();
            $table->foreignUuid('reversal_cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'correction_number']);
            $table->unique(['company_id', 'original_payment_id', 'correction_type']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'correction_type', 'status']);
        });

        Schema::table('payment_instructions', function (Blueprint $table) {
            $table->foreign('reversal_correction_id')->references('id')->on('payment_corrections')->restrictOnDelete();
        });

        Schema::create('payment_advances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('original_amount', 20, 6);
            $table->decimal('applied_amount', 20, 6)->default(0);
            $table->decimal('available_amount', 20, 6);
            $table->string('status', 24)->default('available');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'payment_instruction_id']);
            $table->index(['company_id', 'supplier_id', 'currency_id', 'status']);
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreign('payment_advance_id')->references('id')->on('payment_advances')->restrictOnDelete();
        });

        Schema::create('payment_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 80);
            $table->string('name', 180)->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->foreignUuid('cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignUuid('currency_id')->nullable()->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('control_total', 20, 6)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'batch_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('payment_batch_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_batch_id')->constrained('payment_batches')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->restrictOnDelete();
            $table->string('status', 24)->default('queued');
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_batch_id', 'payment_instruction_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payment_batch_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_batch_id')->constrained('payment_batches')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'payment_batch_id', 'created_at']);
        });

        $permissions = [
            'payments.corrections.create' => 'Create Payment Corrections',
            'payments.corrections.reverse' => 'Reverse Confirmed Payments',
            'payments.allocations.unapply' => 'Unapply Payment Allocations',
            'payments.allocations.reallocate' => 'Reallocate Payment Allocations',
            'payments.advances.view' => 'View Supplier Advances',
            'payments.advances.create' => 'Create Supplier Advances',
            'payments.batches.view' => 'View Payment Batches',
            'payments.batches.create' => 'Create Payment Batches',
            'payments.batches.update' => 'Update Payment Batches',
            'payments.batches.submit' => 'Submit Payment Batches',
            'payments.batches.approve' => 'Approve Payment Batches',
            'payments.batches.release' => 'Release Payment Batches',
            'payments.batches.execute' => 'Execute Payment Batches',
            'payments.checks.view' => 'View Check Register',
            'payments.checks.print' => 'Print Checks',
            'payments.checks.sign' => 'Sign Checks',
            'payments.checks.stop' => 'Stop Checks',
            'payments.checks.void' => 'Void Checks',
            'payments.checks.replace' => 'Replace Checks',
            'payments.recovery.view' => 'View Payment Recovery',
            'payments.recovery.retry' => 'Retry Payment Execution',
            'payments.recovery.resolve' => 'Resolve Payment Execution',
            'payments.duplicates.review' => 'Review Payment Duplicates',
            'payments.reports.view' => 'View Payment Reports',
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
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('module', 'payments')->whereIn('key', [
            'payments.corrections.create', 'payments.corrections.reverse', 'payments.allocations.unapply', 'payments.allocations.reallocate',
            'payments.advances.view', 'payments.advances.create', 'payments.batches.view', 'payments.batches.create', 'payments.batches.update',
            'payments.batches.submit', 'payments.batches.approve', 'payments.batches.release', 'payments.batches.execute', 'payments.checks.view',
            'payments.checks.print', 'payments.checks.sign', 'payments.checks.stop', 'payments.checks.void', 'payments.checks.replace',
            'payments.recovery.view', 'payments.recovery.retry', 'payments.recovery.resolve', 'payments.duplicates.review', 'payments.reports.view',
        ])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('payment_batch_status_histories');
        Schema::dropIfExists('payment_batch_items');
        Schema::dropIfExists('payment_batches');
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['payment_advance_id']);
            $table->dropColumn(['parent_allocation_id', 'payment_advance_id', 'correction_type', 'correction_reason']);
        });
        Schema::dropIfExists('payment_advances');
        Schema::table('payment_instructions', function (Blueprint $table) {
            $table->dropForeign(['reversal_correction_id']);
            $table->dropColumn(['source_kind', 'reversal_correction_id']);
        });
        Schema::dropIfExists('payment_corrections');
        Schema::table('payment_instruments', function (Blueprint $table) {
            $table->dropColumn(['printed_at', 'printed_by', 'signed_at', 'signed_by', 'stopped_at', 'stopped_by', 'stop_reason', 'stop_evidence_reference', 'voided_at', 'voided_by', 'void_reason', 'stale_at', 'replaces_instrument_id', 'replaced_by_instrument_id']);
        });
    }
};
