<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('expense_number', 80);
            $table->date('business_date');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('payee_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $table->string('payee_name_snapshot', 180)->nullable();
            $table->string('external_reference', 180)->nullable();
            $table->text('description');
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->string('settlement_intent', 24)->default('pay_later');
            $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('approval_status', 32)->default('not_required');
            $table->string('payment_status', 32)->default('unpaid');
            $table->string('evidence_status', 32)->default('missing');
            $table->string('duplicate_status', 32)->default('clear');
            $table->boolean('approval_required')->default(false);
            $table->boolean('evidence_required')->default(false);
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('taxable_amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('recoverable_tax_amount', 20, 6)->default(0);
            $table->decimal('nonrecoverable_tax_amount', 20, 6)->default(0);
            $table->decimal('withholding_amount', 20, 6)->default(0);
            $table->decimal('total', 20, 6)->default(0);
            $table->decimal('paid_amount', 20, 6)->default(0);
            $table->decimal('remaining_amount', 20, 6)->default(0);
            $table->text('duplicate_override_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignUuid('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'expense_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'business_date', 'status']);
            $table->index(['company_id', 'payee_id', 'currency_id']);
            $table->index(['company_id', 'approval_status', 'payment_status']);
            $table->index(['company_id', 'due_date', 'remaining_amount']);
            $table->index(['company_id', 'duplicate_status', 'evidence_status']);
        });

        Schema::create('expense_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignUuid('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignUuid('expense_account_title_id')->constrained('account_titles')->restrictOnDelete();
            $table->text('description');
            $table->decimal('quantity', 20, 6)->default(1);
            $table->decimal('unit_amount', 20, 6);
            $table->decimal('line_amount', 20, 6)->default(0);
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->string('tax_basis', 16)->nullable();
            $table->decimal('taxable_amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('recoverable_tax_amount', 20, 6)->default(0);
            $table->decimal('nonrecoverable_tax_amount', 20, 6)->default(0);
            $table->decimal('withholding_amount', 20, 6)->default(0);
            $table->decimal('line_total', 20, 6)->default(0);
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['expense_id', 'line_number']);
            $table->index(['company_id', 'expense_account_title_id', 'expense_category_id']);
            $table->index(['company_id', 'tax_code_id', 'branch_id']);
        });

        Schema::create('expense_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignUuid('expense_line_id')->nullable()->constrained('expense_lines')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('expense_account_title_id')->constrained('account_titles')->restrictOnDelete();
            $table->foreignUuid('expense_category_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->decimal('allocation_percent', 9, 6)->default(100);
            $table->decimal('amount', 20, 6);
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->index(['company_id', 'expense_id', 'status']);
        });

        Schema::create('expense_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('expense_id')->constrained('expenses')->cascadeOnDelete();
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

        Schema::create('expense_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('approval_status', 32)->nullable();
            $table->string('payment_status', 32)->nullable();
            $table->string('event_code', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'expense_id', 'created_at']);
        });

        Schema::create('expense_obligations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('expense_id')->constrained('expenses')->restrictOnDelete();
            $table->foreignUuid('payee_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('original_amount', 20, 6);
            $table->decimal('paid_amount', 20, 6)->default(0);
            $table->decimal('credited_amount', 20, 6)->default(0);
            $table->decimal('refunded_amount', 20, 6)->default(0);
            $table->decimal('adjusted_amount', 20, 6)->default(0);
            $table->decimal('remaining_amount', 20, 6);
            $table->date('due_date');
            $table->string('due_status', 24)->default('not_due');
            $table->boolean('payment_ready')->default(false);
            $table->string('settlement_status', 24)->default('unpaid');
            $table->text('payment_hold_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'expense_id']);
            $table->index(['company_id', 'payee_id', 'currency_id', 'settlement_status']);
            $table->index(['company_id', 'due_date', 'due_status', 'payment_ready']);
        });

        Schema::create('expense_evidences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->string('evidence_type', 32)->default('receipt');
            $table->string('receipt_reference', 180)->nullable();
            $table->date('receipt_date')->nullable();
            $table->string('status', 32)->default('complete');
            $table->string('requirement_status', 32)->default('satisfied');
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['expense_id', 'attachment_id']);
            $table->index(['company_id', 'expense_id', 'status', 'requirement_status']);
        });

        Schema::create('expense_duplicate_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignUuid('candidate_expense_id')->constrained('expenses')->restrictOnDelete();
            $table->string('risk_type', 24)->default('probable');
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('status', 24)->default('open');
            $table->text('resolution_reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['expense_id', 'candidate_expense_id']);
            $table->index(['company_id', 'status', 'risk_type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_settlement_intent_check CHECK (settlement_intent IN ('paid_now','pay_later','reimbursement'))");
            DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('draft','submitted','for_approval','returned','approved','rejected','payment_ready','scheduled','partially_paid','paid','cancelled','adjusted','reversed','closed'))");
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amounts_check CHECK (subtotal >= 0 AND taxable_amount >= 0 AND tax_amount >= 0 AND recoverable_tax_amount >= 0 AND nonrecoverable_tax_amount >= 0 AND withholding_amount >= 0 AND total >= 0 AND paid_amount >= 0 AND remaining_amount >= 0)');
            DB::statement('ALTER TABLE expense_lines ADD CONSTRAINT expense_lines_amounts_check CHECK (quantity > 0 AND unit_amount >= 0 AND line_amount >= 0 AND taxable_amount >= 0 AND tax_amount >= 0 AND recoverable_tax_amount >= 0 AND nonrecoverable_tax_amount >= 0 AND withholding_amount >= 0 AND line_total >= 0)');
            DB::statement('ALTER TABLE expense_allocations ADD CONSTRAINT expense_allocations_percent_check CHECK (allocation_percent > 0 AND allocation_percent <= 100 AND amount > 0)');
            DB::statement('ALTER TABLE expense_obligations ADD CONSTRAINT expense_obligations_amounts_check CHECK (original_amount >= 0 AND paid_amount >= 0 AND credited_amount >= 0 AND refunded_amount >= 0 AND adjusted_amount >= 0 AND remaining_amount >= 0)');
        }

        $permissions = [
            'expenses.view' => 'View Expenses',
            'expenses.history' => 'View Expense History',
            'expenses.unpaid.view' => 'View Unpaid Expenses',
            'expenses.create' => 'Create Expenses',
            'expenses.update' => 'Update Expense Drafts',
            'expenses.submit' => 'Submit Expenses',
            'expenses.cancel' => 'Cancel Expenses',
            'expenses.review' => 'Review Expenses',
            'expenses.approve' => 'Approve Expenses',
            'expenses.reject' => 'Reject Expenses',
            'expenses.return' => 'Return Expenses',
            'expenses.post' => 'Post Expenses',
            'expenses.evidence.upload' => 'Upload Expense Evidence',
            'expenses.evidence.view' => 'View Expense Evidence',
            'expenses.evidence.remove' => 'Remove Expense Evidence',
            'expenses.duplicate.override' => 'Override Expense Duplicate Risk',
            'expenses.account.override' => 'Override Expense Account Mapping',
            'expenses.tax.override' => 'Override Expense Tax',
            'expenses.payment.request' => 'Request Expense Payment',
            'expenses.obligations.view' => 'View Expense Obligations',
        ];
        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'expenses', 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        $memberKeys = ['expenses.view', 'expenses.history', 'expenses.unpaid.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.evidence.upload', 'expenses.evidence.view'];
        $memberIds = DB::table('permissions')->whereIn('key', $memberKeys)->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach ($memberIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('module', 'expenses')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('expense_duplicate_candidates');
        Schema::dropIfExists('expense_evidences');
        Schema::dropIfExists('expense_obligations');
        Schema::dropIfExists('expense_status_histories');
        Schema::dropIfExists('expense_approvals');
        Schema::dropIfExists('expense_allocations');
        Schema::dropIfExists('expense_lines');
        Schema::dropIfExists('expenses');
    }
};
