<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('order_number', 80);
            $table->string('purchase_type', 32)->default('purchase_order');
            $table->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->date('purchase_date');
            $table->date('required_date')->nullable();
            $table->string('supplier_reference', 160)->nullable();
            $table->text('notes')->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->string('status', 32)->default('draft');
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('line_discount_total', 20, 6)->default(0);
            $table->decimal('taxable_amount', 20, 6)->default(0);
            $table->decimal('tax_total', 20, 6)->default(0);
            $table->decimal('total', 20, 6)->default(0);
            $table->decimal('received_amount', 20, 6)->default(0);
            $table->decimal('invoiced_amount', 20, 6)->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'order_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'supplier_id', 'status', 'purchase_date']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->string('description', 240);
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 6)->default(0);
            $table->string('discount_type', 16)->nullable();
            $table->decimal('discount_value', 20, 6)->default(0);
            $table->decimal('gross_amount', 20, 6)->default(0);
            $table->decimal('discount_amount', 20, 6)->default(0);
            $table->decimal('taxable_amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('net_amount', 20, 6)->default(0);
            $table->decimal('received_quantity', 20, 6)->default(0);
            $table->decimal('invoiced_quantity', 20, 6)->default(0);
            $table->decimal('cancelled_quantity', 20, 6)->default(0);
            $table->boolean('stock_managed_snapshot')->default(false);
            $table->boolean('non_stock_snapshot')->default(false);
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->string('tax_code_snapshot', 80)->nullable();
            $table->decimal('tax_rate', 20, 6)->default(0);
            $table->string('tax_basis', 16)->default('exclusive');
            $table->timestamps();
            $table->index(['company_id', 'purchase_order_id']);
            $table->index(['company_id', 'product_service_id']);
        });

        Schema::create('purchase_order_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'purchase_order_id', 'created_at']);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number', 80);
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $table->date('receipt_date');
            $table->string('supplier_delivery_reference', 160)->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->text('explanation')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'receipt_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'supplier_id', 'status', 'receipt_date']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('accepted_quantity', 20, 6)->default(0);
            $table->decimal('rejected_quantity', 20, 6)->default(0);
            $table->decimal('damaged_quantity', 20, 6)->default(0);
            $table->decimal('short_quantity', 20, 6)->default(0);
            $table->decimal('over_quantity', 20, 6)->default(0);
            $table->decimal('backordered_quantity', 20, 6)->default(0);
            $table->text('reason')->nullable();
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->foreignUuid('stock_receipt_id')->nullable()->constrained('stock_receipts')->restrictOnDelete();
            $table->foreignUuid('stock_receipt_line_id')->nullable()->constrained('stock_receipt_lines')->restrictOnDelete();
            $table->foreignUuid('inventory_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'goods_receipt_id']);
            $table->index(['company_id', 'purchase_order_line_id']);
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 80);
            $table->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('external_invoice_number', 160);
            $table->date('invoice_date');
            $table->date('received_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('match_status', 24)->default('not_required');
            $table->text('match_exception')->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('draft');
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('line_discount_total', 20, 6)->default(0);
            $table->decimal('taxable_amount', 20, 6)->default(0);
            $table->decimal('tax_total', 20, 6)->default(0);
            $table->decimal('total', 20, 6)->default(0);
            $table->decimal('paid_amount', 20, 6)->default(0);
            $table->decimal('remaining_amount', 20, 6)->default(0);
            $table->foreignUuid('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('payable_open_item_id')->nullable();
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
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'invoice_number']);
            $table->unique(['company_id', 'supplier_id', 'external_invoice_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'supplier_id', 'status', 'due_date']);
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->foreignUuid('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignUuid('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->string('description', 240);
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 6)->default(0);
            $table->decimal('gross_amount', 20, 6)->default(0);
            $table->decimal('discount_amount', 20, 6)->default(0);
            $table->decimal('taxable_amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('net_amount', 20, 6)->default(0);
            $table->boolean('stock_managed_snapshot')->default(false);
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->string('tax_code_snapshot', 80)->nullable();
            $table->decimal('tax_rate', 20, 6)->default(0);
            $table->string('tax_basis', 16)->default('exclusive');
            $table->timestamps();
            $table->index(['company_id', 'supplier_invoice_id']);
        });

        Schema::create('payable_open_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('source_supplier_invoice_id')->unique()->constrained('supplier_invoices')->restrictOnDelete();
            $table->string('source_document_number', 80);
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('original_amount', 20, 6);
            $table->decimal('paid_amount', 20, 6)->default(0);
            $table->decimal('remaining_amount', 20, 6);
            $table->date('due_date')->nullable();
            $table->date('discount_date')->nullable();
            $table->string('settlement_status', 24)->default('unpaid');
            $table->string('due_status', 24)->default('not_yet_due');
            $table->string('hold_status', 24)->default('not_held');
            $table->text('hold_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'supplier_id', 'settlement_status', 'due_status']);
            $table->index(['company_id', 'due_date']);
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->foreign('payable_open_item_id')->references('id')->on('payable_open_items')->restrictOnDelete();
        });

        Schema::create('purchase_match_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->foreignUuid('supplier_invoice_line_id')->nullable()->constrained('supplier_invoice_lines')->restrictOnDelete();
            $table->string('exception_type', 40);
            $table->string('status', 24)->default('open');
            $table->decimal('expected_amount', 20, 6)->nullable();
            $table->decimal('actual_amount', 20, 6)->nullable();
            $table->text('explanation');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'supplier_invoice_id', 'status']);
        });

        Schema::table('stock_receipt_lines', function (Blueprint $table) {
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();
            $table->string('currency_code', 12)->nullable();
            $table->string('cost_source', 80)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_type_check CHECK (purchase_type IN ('purchase_order','direct_purchase'))");
            DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft','submitted','awaiting_approval','approved','partially_received','fully_received','partially_invoiced','fully_invoiced','closed','cancelled'))");
            DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_quantity_check CHECK (quantity > 0 AND unit_cost >= 0)');
            DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_status_check CHECK (status IN ('draft','validating','posted','partially_accepted','completed','reversed','cancelled'))");
            DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_quantity_check CHECK (quantity > 0 AND accepted_quantity >= 0 AND rejected_quantity >= 0 AND damaged_quantity >= 0)');
            DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_status_check CHECK (status IN ('draft','validating','matched','exception','awaiting_approval','posted','partially_paid','paid','overdue','on_hold','disputed','reversed','cancelled'))");
            DB::statement('ALTER TABLE supplier_invoice_lines ADD CONSTRAINT supplier_invoice_lines_quantity_check CHECK (quantity > 0 AND unit_cost >= 0)');
            DB::statement('ALTER TABLE payable_open_items ADD CONSTRAINT payable_open_items_amount_check CHECK (original_amount >= 0 AND paid_amount >= 0 AND remaining_amount >= 0)');
        }

        $permissions = [
            'purchases.view' => 'View Purchases', 'purchases.create' => 'Create Purchases', 'purchases.update' => 'Update Purchase Drafts',
            'purchases.submit' => 'Submit Purchases', 'purchases.review' => 'Review Purchases', 'purchases.approve' => 'Approve Purchases',
            'purchases.cancel' => 'Cancel Purchases', 'purchases.close' => 'Close Purchases', 'purchases.history' => 'View Purchase History',
            'purchases.orders.view' => 'View Purchase Orders', 'purchases.orders.create' => 'Create Purchase Orders', 'purchases.orders.update' => 'Update Purchase Orders',
            'purchases.orders.submit' => 'Submit Purchase Orders', 'purchases.orders.review' => 'Review Purchase Orders', 'purchases.orders.approve' => 'Approve Purchase Orders',
            'purchases.orders.cancel' => 'Cancel Purchase Orders', 'purchases.orders.close' => 'Close Purchase Orders',
            'purchases.receipts.view' => 'View Goods Receipts', 'purchases.receipts.create' => 'Create Goods Receipts', 'purchases.receipts.submit' => 'Submit Goods Receipts',
            'purchases.receipts.post' => 'Post Goods Receipts', 'purchases.receipts.reverse' => 'Reverse Goods Receipts',
            'purchases.invoices.view' => 'View Supplier Invoices', 'purchases.invoices.create' => 'Create Supplier Invoices', 'purchases.invoices.update' => 'Update Supplier Invoices',
            'purchases.invoices.submit' => 'Submit Supplier Invoices', 'purchases.invoices.approve' => 'Approve Supplier Invoices', 'purchases.invoices.post' => 'Post Supplier Invoices',
            'purchases.invoices.reverse' => 'Reverse Supplier Invoices', 'purchases.payables.view' => 'View Payables', 'purchases.payables.aging.view' => 'View Payables Aging',
            'purchases.reports.view' => 'View Purchase Reports', 'purchases.match.override' => 'Override Purchase Match Exceptions',
            'purchases.over-receipt.override' => 'Override Purchase Over Receipts',
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
        $ids = DB::table('permissions')->where('module', 'purchases')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::table('stock_receipt_lines', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'total_cost', 'currency_code', 'cost_source']);
        });
        Schema::dropIfExists('purchase_match_exceptions');
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('payable_open_items');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_status_histories');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
