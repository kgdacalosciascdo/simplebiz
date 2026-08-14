<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignUuid('customer_id')->nullable()->change();
            $table->string('other_receipt_type', 60)->nullable()->after('receipt_type');
            $table->string('counterparty_name', 180)->nullable();
            $table->string('source_module', 80)->nullable();
            $table->string('source_reference', 160)->nullable();
            $table->string('classification', 80)->nullable();
            $table->string('business_purpose', 500)->nullable();
            $table->string('evidence_reference', 180)->nullable();
            $table->text('notes')->nullable();
            $table->index(['company_id', 'receipt_type', 'receipt_date']);
        });

        Schema::table('receipt_tenders', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable();
            $table->foreignId('failed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->string('failure_reference', 180)->nullable();
            $table->string('remittance_status', 24)->default('unremitted');
            $table->foreignUuid('replaced_by_tender_id')->nullable();
            $table->index(['company_id', 'remittance_status', 'instrument_status']);
        });

        Schema::create('other_receipt_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 160);
            $table->string('classification', 80);
            $table->string('source_module', 80)->nullable();
            $table->boolean('requires_counterparty')->default(true);
            $table->boolean('requires_source_reference')->default(false);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });

        Schema::create('receipt_reprints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('receipt_id')->constrained('receipts')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('channel', 40)->default('screen');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'receipt_id', 'created_at']);
        });

        Schema::create('collection_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('receipt_id')->nullable()->constrained('receipts')->restrictOnDelete();
            $table->foreignUuid('receivable_open_item_id')->nullable()->constrained('receivable_open_items')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity_type', 40);
            $table->string('status', 32)->default('planned');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->date('promise_date')->nullable();
            $table->decimal('promise_amount', 20, 6)->nullable();
            $table->string('dispute_reason', 500)->nullable();
            $table->string('resolution', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('source_reference', 180)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'next_action_at']);
            $table->index(['company_id', 'customer_id', 'occurred_at']);
        });

        Schema::create('cash_remittances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('remittance_number', 60);
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('destination_cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->date('remittance_date');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 24)->default('draft');
            $table->decimal('expected_amount', 20, 6)->default(0);
            $table->decimal('submitted_amount', 20, 6)->default(0);
            $table->decimal('difference_amount', 20, 6)->default(0);
            $table->string('evidence_reference', 180)->nullable();
            $table->string('deposit_reference', 180)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'remittance_number']);
            $table->index(['company_id', 'status', 'remittance_date']);
        });

        Schema::create('cash_remittance_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_remittance_id')->constrained('cash_remittances')->cascadeOnDelete();
            $table->foreignUuid('receipt_tender_id')->constrained('receipt_tenders')->restrictOnDelete();
            $table->decimal('expected_amount', 20, 6);
            $table->decimal('submitted_amount', 20, 6);
            $table->decimal('difference_amount', 20, 6)->default(0);
            $table->string('status', 24)->default('included');
            $table->timestamps();
            $table->unique(['cash_remittance_id', 'receipt_tender_id']);
            $table->index(['company_id', 'receipt_tender_id']);
        });

        Schema::create('remittance_variances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cash_remittance_id')->constrained('cash_remittances')->cascadeOnDelete();
            $table->decimal('expected_amount', 20, 6);
            $table->decimal('actual_amount', 20, 6);
            $table->decimal('difference_amount', 20, 6);
            $table->string('status', 24)->default('open');
            $table->text('reason')->nullable();
            $table->text('resolution')->nullable();
            $table->string('evidence_reference', 180)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE receipts DROP CONSTRAINT IF EXISTS receipts_type_check');
            DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_type_check CHECK (receipt_type IN ('customer_collection','advance_unapplied_customer_receipt','paid_now_sale_receipt','other_receipt'))");
            DB::statement("ALTER TABLE receipt_tenders ADD CONSTRAINT receipt_tenders_instrument_status_check CHECK (instrument_status IN ('not_applicable','pending','confirmed','cleared','failed','returned','reversed'))");
            DB::statement("ALTER TABLE cash_remittances ADD CONSTRAINT cash_remittances_status_check CHECK (status IN ('draft','submitted','verified','with_variance','accepted','rejected','reversed'))");
            DB::statement("ALTER TABLE remittance_variances ADD CONSTRAINT remittance_variances_status_check CHECK (status IN ('open','under_review','explained','approved','recovered','written_off','reversed'))");
        }

        $this->seedOtherReceiptTypes();
        $this->seedPermissions();
    }

    private function seedOtherReceiptTypes(): void
    {
        $types = [
            ['OWNER_CONTRIBUTION', 'Owner contribution', 'equity', null, true, false],
            ['LOAN_PROCEEDS', 'Loan proceeds', 'liability', null, true, true],
            ['DEPOSIT_REFUND', 'Deposit refund', 'other_income', null, true, true],
            ['INTEREST_INCOME', 'Interest income', 'income', null, true, true],
            ['INSURANCE_PROCEEDS', 'Insurance proceeds', 'other_income', null, true, true],
            ['ASSET_SALE_PROCEEDS', 'Asset sale proceeds', 'other_income', 'asset', true, true],
            ['OTHER_AUTHORIZED_INFLOW', 'Other authorized inflow', 'other_income', null, true, true],
        ];
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ($types as [$code, $name, $classification, $sourceModule, $requiresCounterparty, $requiresReference]) {
                DB::table('other_receipt_types')->updateOrInsert(
                    ['company_id' => $companyId, 'code' => $code],
                    ['id' => (string) Str::uuid(), 'name' => $name, 'classification' => $classification, 'source_module' => $sourceModule, 'requires_counterparty' => $requiresCounterparty, 'requires_source_reference' => $requiresReference, 'requires_approval' => true, 'active' => true, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    private function seedPermissions(): void
    {
        $permissions = [
            'collections.receipts.print' => 'Print Receipts',
            'collections.receipts.reprint' => 'Reprint Receipts',
            'collections.receipts.export' => 'Export Receipts',
            'collections.instruments.fail' => 'Handle Failed Payment Instruments',
            'collections.activities.view' => 'View Collection Activities',
            'collections.activities.create' => 'Record Collection Activities',
            'collections.activities.manage' => 'Manage Collection Activities',
            'collections.remittance.view' => 'View Cash Remittances',
            'collections.remittance.create' => 'Prepare Cash Remittances',
            'collections.remittance.submit' => 'Submit Cash Remittances',
            'collections.remittance.verify' => 'Verify Cash Remittances',
            'collections.remittance.accept' => 'Accept Cash Remittances',
            'collections.remittance.variance' => 'Record Remittance Variances',
            'collections.remittance.resolve' => 'Resolve Remittance Variances',
            'collections.other_receipts.view' => 'View Other Receipts',
            'collections.other_receipts.create' => 'Create Other Receipts',
            'collections.other_receipts.approve' => 'Approve Other Receipts',
            'collections.other_receipts.post' => 'Post Other Receipts',
            'collections.other_receipts.reverse' => 'Reverse Other Receipts',
            'collections.reports.view' => 'View Collections Reports',
        ];
        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'collections', 'created_at' => now(), 'updated_at' => now()]);
        }
        $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        $viewKeys = ['collections.receipts.print', 'collections.activities.view', 'collections.remittance.view', 'collections.other_receipts.view', 'collections.reports.view'];
        $viewIds = DB::table('permissions')->whereIn('key', $viewKeys)->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach ($viewIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_variances');
        Schema::dropIfExists('cash_remittance_lines');
        Schema::dropIfExists('cash_remittances');
        Schema::dropIfExists('collection_activities');
        Schema::dropIfExists('receipt_reprints');
        Schema::dropIfExists('other_receipt_types');
        Schema::table('receipt_tenders', function (Blueprint $table) {
            $table->dropForeign(['failed_by']);
            $table->dropColumn(['failed_at', 'failed_by', 'failure_reason', 'failure_reference', 'remittance_status', 'replaced_by_tender_id']);
        });
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn(['other_receipt_type', 'counterparty_name', 'source_module', 'source_reference', 'classification', 'business_purpose', 'evidence_reference', 'notes']);
            $table->foreignUuid('customer_id')->nullable(false)->change();
        });
    }
};
