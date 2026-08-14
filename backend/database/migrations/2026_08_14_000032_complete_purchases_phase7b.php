<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->decimal('match_tolerance_amount', 20, 6)->default(0)->after('match_exception');
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('return_number', 80);
            $table->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignUuid('goods_receipt_id')->nullable()->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->date('return_date');
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->string('supplier_authorization_reference', 160)->nullable();
            $table->string('shipping_reference', 160)->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->text('explanation')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignUuid('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'return_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'supplier_id', 'status', 'return_date']);
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_return_id')->constrained('purchase_returns')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('goods_receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->foreignUuid('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $table->decimal('original_received_quantity', 20, 6);
            $table->decimal('previously_returned_quantity', 20, 6)->default(0);
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 6)->default(0);
            $table->decimal('total_cost', 20, 6)->default(0);
            $table->boolean('stock_managed_snapshot')->default(false);
            $table->string('condition', 32)->default('returned');
            $table->text('reason')->nullable();
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->foreignUuid('stock_issue_id')->nullable()->constrained('stock_issues')->restrictOnDelete();
            $table->foreignUuid('stock_issue_line_id')->nullable()->constrained('stock_issue_lines')->restrictOnDelete();
            $table->foreignUuid('inventory_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'goods_receipt_line_id']);
            $table->index(['company_id', 'purchase_return_id']);
        });

        Schema::create('purchase_return_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_return_id')->constrained('purchase_returns')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'purchase_return_id', 'created_at']);
        });

        Schema::create('supplier_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('adjustment_number', 80);
            $table->string('adjustment_type', 16);
            $table->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->restrictOnDelete();
            $table->foreignUuid('purchase_return_id')->nullable()->constrained('purchase_returns')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->date('adjustment_date');
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->string('external_reference', 160)->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->text('explanation')->nullable();
            $table->decimal('amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('total_amount', 20, 6)->default(0);
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignUuid('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'adjustment_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'supplier_id', 'adjustment_type', 'status']);
        });

        Schema::create('supplier_adjustment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_adjustment_id')->constrained('supplier_adjustments')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_line_id')->nullable()->constrained('supplier_invoice_lines')->restrictOnDelete();
            $table->foreignUuid('purchase_return_line_id')->nullable()->constrained('purchase_return_lines')->restrictOnDelete();
            $table->foreignUuid('product_service_id')->nullable()->constrained('products_services')->restrictOnDelete();
            $table->string('description', 240);
            $table->decimal('quantity', 20, 6)->nullable();
            $table->decimal('unit_amount', 20, 6)->default(0);
            $table->decimal('amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('total_amount', 20, 6)->default(0);
            $table->string('product_code_snapshot', 80)->nullable();
            $table->string('product_name_snapshot', 180)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'supplier_adjustment_id']);
        });

        Schema::create('supplier_adjustment_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_adjustment_id')->constrained('supplier_adjustments')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'supplier_adjustment_id', 'created_at']);
        });

        Schema::create('supplier_invoice_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('correction_number', 80);
            $table->string('correction_type', 24)->default('reversal');
            $table->foreignUuid('original_supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->date('correction_date');
            $table->text('reason');
            $table->string('evidence_reference', 255)->nullable();
            $table->decimal('amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('total_amount', 20, 6)->default(0);
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignUuid('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'correction_number']);
            $table->unique(['company_id', 'original_supplier_invoice_id', 'correction_type']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'supplier_id', 'status']);
        });

        Schema::create('payable_effects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payable_open_item_id')->constrained('payable_open_items')->restrictOnDelete();
            $table->string('source_type', 120);
            $table->uuid('source_id');
            $table->string('effect_type', 40);
            $table->decimal('amount_delta', 20, 6);
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->text('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('reversal_effect_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'source_type', 'source_id', 'effect_type']);
            $table->index(['company_id', 'payable_open_item_id', 'created_at']);
        });

        Schema::table('payable_effects', function (Blueprint $table) {
            $table->foreign('reversal_effect_id')->references('id')->on('payable_effects')->restrictOnDelete();
        });

        Schema::create('payable_hold_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payable_open_item_id')->constrained('payable_open_items')->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('reason');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'payable_open_item_id', 'created_at']);
        });

        Schema::create('purchase_match_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->string('match_method', 24)->default('none');
            $table->string('status', 24);
            $table->decimal('tolerance_amount', 20, 6)->default(0);
            $table->decimal('variance_amount', 20, 6)->default(0);
            $table->text('summary')->nullable();
            $table->jsonb('exceptions_snapshot')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'supplier_invoice_id', 'created_at']);
        });

        Schema::create('purchase_order_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('revision_type', 24);
            $table->text('reason');
            $table->jsonb('before_snapshot');
            $table->jsonb('after_snapshot');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'purchase_order_id', 'revision_number']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_status_check CHECK (status IN ('draft','approved','posted','in_transit','acknowledged','credited','replaced','closed','reversed','cancelled'))");
            DB::statement('ALTER TABLE purchase_return_lines ADD CONSTRAINT purchase_return_lines_quantity_check CHECK (quantity > 0 AND original_received_quantity >= 0 AND previously_returned_quantity >= 0 AND quantity <= original_received_quantity)');
            DB::statement("ALTER TABLE supplier_adjustments ADD CONSTRAINT supplier_adjustments_type_check CHECK (adjustment_type IN ('debit','credit'))");
            DB::statement("ALTER TABLE supplier_adjustments ADD CONSTRAINT supplier_adjustments_status_check CHECK (status IN ('draft','awaiting_approval','posted','applied','reversed','cancelled','eligible'))");
            DB::statement("ALTER TABLE supplier_invoice_corrections ADD CONSTRAINT supplier_invoice_corrections_status_check CHECK (status IN ('draft','awaiting_approval','posted','reversed','cancelled'))");
            DB::statement('ALTER TABLE payable_effects ADD CONSTRAINT payable_effects_delta_check CHECK (amount_delta <> 0)');
        }

        $permissions = [
            'purchases.orders.amend' => 'Amend Purchase Orders',
            'purchases.orders.reopen' => 'Reopen Purchase Orders',
            'purchases.receipts.correct' => 'Correct Goods Receipts',
            'purchases.returns.view' => 'View Purchase Returns',
            'purchases.returns.create' => 'Create Purchase Returns',
            'purchases.returns.update' => 'Update Purchase Return Drafts',
            'purchases.returns.submit' => 'Submit Purchase Returns',
            'purchases.returns.review' => 'Review Purchase Returns',
            'purchases.returns.approve' => 'Approve Purchase Returns',
            'purchases.returns.post' => 'Post Purchase Returns',
            'purchases.returns.reverse' => 'Reverse Purchase Returns',
            'purchases.invoices.correction.view' => 'View Supplier Invoice Corrections',
            'purchases.invoices.correction.create' => 'Create Supplier Invoice Corrections',
            'purchases.invoices.correction.post' => 'Post Supplier Invoice Corrections',
            'purchases.adjustments.view' => 'View Supplier Adjustments',
            'purchases.adjustments.create' => 'Create Supplier Adjustments',
            'purchases.adjustments.update' => 'Update Supplier Adjustment Drafts',
            'purchases.adjustments.submit' => 'Submit Supplier Adjustments',
            'purchases.adjustments.approve' => 'Approve Supplier Adjustments',
            'purchases.adjustments.post' => 'Post Supplier Adjustments',
            'purchases.adjustments.reverse' => 'Reverse Supplier Adjustments',
            'purchases.matches.view' => 'View Purchase Matching',
            'purchases.matches.resolve' => 'Resolve Purchase Match Exceptions',
            'purchases.payables.effects.view' => 'View Payable Effects',
            'purchases.payables.hold' => 'Place Payables on Hold',
            'purchases.payables.release' => 'Release Payable Holds',
            'purchases.ledger.view' => 'View Supplier Ledger',
        ];
        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'purchases', 'created_at' => now(), 'updated_at' => now()]);
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
        $ids = DB::table('permissions')->where('module', 'purchases')->whereIn('key', [
            'purchases.orders.amend', 'purchases.orders.reopen', 'purchases.receipts.correct', 'purchases.returns.view', 'purchases.returns.create', 'purchases.returns.update', 'purchases.returns.submit', 'purchases.returns.review', 'purchases.returns.approve', 'purchases.returns.post', 'purchases.returns.reverse', 'purchases.invoices.correction.view', 'purchases.invoices.correction.create', 'purchases.invoices.correction.post', 'purchases.adjustments.view', 'purchases.adjustments.create', 'purchases.adjustments.update', 'purchases.adjustments.submit', 'purchases.adjustments.approve', 'purchases.adjustments.post', 'purchases.adjustments.reverse', 'purchases.matches.view', 'purchases.matches.resolve', 'purchases.payables.effects.view', 'purchases.payables.hold', 'purchases.payables.release', 'purchases.ledger.view',
        ])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('purchase_order_revisions');
        Schema::dropIfExists('purchase_match_histories');
        Schema::dropIfExists('payable_hold_histories');
        Schema::dropIfExists('payable_effects');
        Schema::dropIfExists('supplier_invoice_corrections');
        Schema::dropIfExists('supplier_adjustment_status_histories');
        Schema::dropIfExists('supplier_adjustment_lines');
        Schema::dropIfExists('supplier_adjustments');
        Schema::dropIfExists('purchase_return_status_histories');
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn('match_tolerance_amount');
        });
    }
};
