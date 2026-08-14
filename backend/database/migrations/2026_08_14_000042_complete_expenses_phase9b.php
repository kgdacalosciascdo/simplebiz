<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimbursement_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('claim_number', 80);
            $table->foreignId('claimant_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('claimant_business_partner_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->text('business_purpose');
            $table->string('status', 32)->default('draft');
            $table->string('approval_status', 32)->default('not_required');
            $table->string('payment_status', 32)->default('unpaid');
            $table->string('evidence_status', 32)->default('complete');
            $table->boolean('approval_required')->default(false);
            $table->boolean('evidence_required')->default(false);
            $table->decimal('total', 20, 6)->default(0);
            $table->decimal('paid_amount', 20, 6)->default(0);
            $table->decimal('remaining_amount', 20, 6)->default(0);
            $table->date('due_date')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
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
            $table->unique(['company_id', 'claim_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'claimant_user_id', 'status']);
        });

        Schema::create('reimbursement_claim_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reimbursement_claim_id')->constrained('reimbursement_claims')->cascadeOnDelete();
            $table->foreignUuid('expense_id')->constrained('expenses')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('claimed_amount', 20, 6);
            $table->string('status', 24)->default('included');
            $table->timestamps();
            $table->unique(['reimbursement_claim_id', 'expense_id']);
            $table->unique(['company_id', 'expense_id']);
        });

        Schema::create('reimbursement_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reimbursement_claim_id')->constrained('reimbursement_claims')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('action', 32)->default('submitted');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->unsignedInteger('submitted_version')->default(1);
            $table->timestamp('acted_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('reimbursement_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reimbursement_claim_id')->constrained('reimbursement_claims')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('event_code', 40);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'reimbursement_claim_id', 'created_at']);
        });

        Schema::create('reimbursement_obligations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reimbursement_claim_id')->unique()->constrained('reimbursement_claims')->restrictOnDelete();
            $table->foreignId('claimant_user_id')->constrained('users')->restrictOnDelete();
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
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'claimant_user_id', 'settlement_status']);
            $table->index(['company_id', 'due_date', 'payment_ready']);
        });

        Schema::create('recurring_expense_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('template_number', 80);
            $table->string('name', 180);
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('payee_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('expense_category_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->foreignUuid('expense_account_title_id')->nullable()->constrained('account_titles')->restrictOnDelete();
            $table->string('settlement_intent', 24)->default('pay_later');
            $table->string('frequency', 32);
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->boolean('active')->default(true);
            $table->decimal('amount', 20, 6);
            $table->text('description');
            $table->json('line_defaults')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'template_number']);
            $table->index(['company_id', 'active', 'next_run_date']);
        });

        Schema::create('recurring_expense_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('recurring_expense_templates')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->foreignUuid('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->string('status', 24)->default('scheduled');
            $table->text('generation_note')->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'scheduled_date']);
            $table->index(['company_id', 'status', 'scheduled_date']);
        });

        Schema::create('expense_adjustment_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('expense_id')->constrained('expenses')->restrictOnDelete();
            $table->foreignUuid('expense_obligation_id')->nullable()->constrained('expense_obligations')->restrictOnDelete();
            $table->string('adjustment_type', 24);
            $table->decimal('amount', 20, 6);
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->string('status', 24)->default('posted');
            $table->text('reason');
            $table->date('effective_date');
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('cash_movement_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'expense_id', 'adjustment_type']);
            $table->index(['company_id', 'status', 'effective_date']);
        });

        Schema::create('expense_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 80);
            $table->string('status', 24)->default('previewed');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_row_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'batch_number']);
        });

        Schema::create('expense_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('expense_import_batches')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('payload');
            $table->json('errors')->nullable();
            $table->string('status', 24)->default('valid');
            $table->foreignUuid('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->timestamps();
            $table->unique(['batch_id', 'row_number']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignUuid('reimbursement_claim_id')->nullable()->after('payee_id')->constrained('reimbursement_claims')->nullOnDelete();
            $table->foreignUuid('source_recurring_template_id')->nullable()->after('reimbursement_claim_id')->constrained('recurring_expense_templates')->nullOnDelete();
            $table->foreignUuid('source_recurring_occurrence_id')->nullable()->after('source_recurring_template_id')->constrained('recurring_expense_occurrences')->nullOnDelete();
            $table->foreignUuid('copy_source_expense_id')->nullable()->after('source_recurring_occurrence_id')->constrained('expenses')->nullOnDelete();
            $table->foreignUuid('functional_currency_id')->nullable()->after('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 20, 10)->default(1)->after('functional_currency_id');
            $table->decimal('functional_subtotal', 20, 6)->default(0)->after('remaining_amount');
            $table->decimal('functional_tax_amount', 20, 6)->default(0)->after('functional_subtotal');
            $table->decimal('functional_total', 20, 6)->default(0)->after('functional_tax_amount');
            $table->decimal('functional_paid_amount', 20, 6)->default(0)->after('functional_total');
            $table->decimal('functional_remaining_amount', 20, 6)->default(0)->after('functional_paid_amount');
            $table->index(['company_id', 'reimbursement_claim_id']);
            $table->index(['company_id', 'source_recurring_template_id']);
        });

        Schema::table('payment_instruction_sources', function (Blueprint $table) {
            $table->foreignUuid('reimbursement_obligation_id')->nullable()->after('expense_obligation_id')->constrained('reimbursement_obligations')->restrictOnDelete();
            $table->index(['company_id', 'reimbursement_obligation_id']);
            $table->unique(['payment_instruction_id', 'reimbursement_obligation_id']);
        });
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignUuid('reimbursement_obligation_id')->nullable()->after('expense_obligation_id')->constrained('reimbursement_obligations')->restrictOnDelete();
            $table->index(['company_id', 'reimbursement_obligation_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_instruction_sources DROP CONSTRAINT IF EXISTS payment_instruction_sources_one_owner');
            DB::statement('ALTER TABLE payment_allocations DROP CONSTRAINT IF EXISTS payment_allocations_one_owner');
            DB::statement('ALTER TABLE payment_instruction_sources ADD CONSTRAINT payment_instruction_sources_one_owner CHECK (num_nonnulls(payable_open_item_id, expense_obligation_id, reimbursement_obligation_id) = 1)');
            DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_one_owner CHECK (num_nonnulls(payable_open_item_id, expense_obligation_id, reimbursement_obligation_id) = 1)');
        }

        $permissions = [
            'expenses.copy' => 'Copy Expenses',
            'expenses.reimbursements.view' => 'View Reimbursement Claims',
            'expenses.reimbursements.create' => 'Create Reimbursement Claims',
            'expenses.reimbursements.approve' => 'Approve Reimbursement Claims',
            'expenses.reimbursements.payment.request' => 'Request Reimbursement Payments',
            'expenses.recurring.view' => 'View Recurring Expenses',
            'expenses.recurring.manage' => 'Manage Recurring Expenses',
            'expenses.corrections.create' => 'Create Expense Corrections',
            'expenses.reports.view' => 'View Expense Reports',
            'expenses.import' => 'Import Expenses',
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
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['reimbursement_obligation_id']);
            $table->dropIndex(['company_id', 'reimbursement_obligation_id', 'status']);
            $table->dropColumn('reimbursement_obligation_id');
        });
        Schema::table('payment_instruction_sources', function (Blueprint $table) {
            $table->dropUnique(['payment_instruction_id', 'reimbursement_obligation_id']);
            $table->dropForeign(['reimbursement_obligation_id']);
            $table->dropIndex(['company_id', 'reimbursement_obligation_id']);
            $table->dropColumn('reimbursement_obligation_id');
        });
        Schema::dropIfExists('expense_import_rows');
        Schema::dropIfExists('expense_import_batches');
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['reimbursement_claim_id']);
            $table->dropForeign(['source_recurring_template_id']);
            $table->dropForeign(['source_recurring_occurrence_id']);
            $table->dropForeign(['copy_source_expense_id']);
            $table->dropForeign(['functional_currency_id']);
            $table->dropIndex(['company_id', 'reimbursement_claim_id']);
            $table->dropIndex(['company_id', 'source_recurring_template_id']);
            $table->dropColumn(['reimbursement_claim_id', 'source_recurring_template_id', 'source_recurring_occurrence_id', 'copy_source_expense_id', 'functional_currency_id', 'exchange_rate', 'functional_subtotal', 'functional_tax_amount', 'functional_total', 'functional_paid_amount', 'functional_remaining_amount']);
        });
        Schema::dropIfExists('expense_adjustment_entries');
        Schema::dropIfExists('recurring_expense_occurrences');
        Schema::dropIfExists('recurring_expense_templates');
        Schema::dropIfExists('reimbursement_obligations');
        Schema::dropIfExists('reimbursement_status_histories');
        Schema::dropIfExists('reimbursement_approvals');
        Schema::dropIfExists('reimbursement_claim_expenses');
        Schema::dropIfExists('reimbursement_claims');
        $ids = DB::table('permissions')->where('module', 'expenses')->whereIn('key', ['expenses.copy', 'expenses.reimbursements.view', 'expenses.reimbursements.create', 'expenses.reimbursements.approve', 'expenses.reimbursements.payment.request', 'expenses.recurring.view', 'expenses.recurring.manage', 'expenses.corrections.create', 'expenses.reports.view', 'expenses.import'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
