<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_lines', 'warehouse_id')) {
                $table->foreignUuid('warehouse_id')->nullable()->after('product_service_id')->constrained('warehouses')->restrictOnDelete();
            }
            if (! Schema::hasColumn('sale_lines', 'stock_location_id')) {
                $table->foreignUuid('stock_location_id')->nullable()->after('warehouse_id')->constrained('stock_locations')->restrictOnDelete();
            }
        });

        Schema::table('billing_statements', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_statements', 'source_snapshot')) {
                $table->json('source_snapshot')->nullable()->after('filters');
            }
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('return_number', 80);
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->date('return_date');
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->string('evidence_reference', 255)->nullable();
            $table->text('explanation');
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('total_amount', 20, 6)->default(0);
            $table->decimal('customer_credit_amount', 20, 6)->default(0);
            $table->string('refund_status', 32)->default('not_required');
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
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
            $table->index(['company_id', 'sale_id', 'status', 'return_date']);
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_return_id')->constrained('sales_returns')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_line_id')->constrained('sale_lines')->restrictOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $table->decimal('original_quantity', 20, 6);
            $table->decimal('previously_returned_quantity', 20, 6)->default(0);
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 20, 6)->default(0);
            $table->decimal('taxable_amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('total_amount', 20, 6)->default(0);
            $table->boolean('stock_managed_snapshot')->default(false);
            $table->boolean('service_snapshot')->default(false);
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40)->nullable();
            $table->string('unit_name_snapshot', 120)->nullable();
            $table->text('reason')->nullable();
            $table->uuid('inventory_movement_id')->nullable();
            $table->timestamps();
            $table->unique(['sales_return_id', 'sale_line_id']);
            $table->index(['company_id', 'sale_line_id']);
        });

        Schema::create('sales_return_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_return_id')->constrained('sales_returns')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'sales_return_id', 'created_at']);
        });

        Schema::create('sales_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('adjustment_number', 80);
            $table->string('adjustment_type', 16);
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->date('adjustment_date');
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->string('evidence_reference', 255)->nullable();
            $table->text('explanation');
            $table->decimal('amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('total_amount', 20, 6)->default(0);
            $table->decimal('customer_credit_amount', 20, 6)->default(0);
            $table->string('refund_status', 32)->default('not_required');
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
            $table->index(['company_id', 'sale_id', 'adjustment_type', 'status']);
        });

        Schema::create('sales_adjustment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_adjustment_id')->constrained('sales_adjustments')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_line_id')->nullable()->constrained('sale_lines')->restrictOnDelete();
            $table->foreignUuid('product_service_id')->nullable()->constrained('products_services')->restrictOnDelete();
            $table->string('description', 240);
            $table->decimal('quantity', 20, 6)->nullable();
            $table->decimal('unit_amount', 20, 6)->default(0);
            $table->decimal('amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('total_amount', 20, 6)->default(0);
            $table->timestamps();
            $table->index(['company_id', 'sales_adjustment_id']);
        });

        Schema::create('sales_adjustment_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_adjustment_id')->constrained('sales_adjustments')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'sales_adjustment_id', 'created_at']);
        });

        Schema::create('sales_receivable_effects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('receivable_open_item_id')->constrained('receivable_open_items')->restrictOnDelete();
            $table->string('source_type', 120);
            $table->uuid('source_id');
            $table->string('effect_type', 64);
            $table->decimal('amount_delta', 20, 6);
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->text('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('reversal_effect_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'source_type', 'source_id', 'effect_type']);
            $table->index(['company_id', 'receivable_open_item_id', 'created_at']);
        });

        Schema::table('sales_receivable_effects', function (Blueprint $table) {
            $table->foreign('reversal_effect_id')->references('id')->on('sales_receivable_effects')->restrictOnDelete();
        });

        $permissions = [
            'sales.reverse' => 'Reverse Posted Sales',
            'sales.returns.view' => 'View Sales Returns',
            'sales.returns.create' => 'Create Sales Returns',
            'sales.returns.update' => 'Update Sales Returns',
            'sales.returns.submit' => 'Submit Sales Returns',
            'sales.returns.review' => 'Review Sales Returns',
            'sales.returns.approve' => 'Approve Sales Returns',
            'sales.returns.post' => 'Post Sales Returns',
            'sales.returns.reverse' => 'Reverse Sales Returns',
            'sales.adjustments.view' => 'View Sales Adjustments',
            'sales.adjustments.create' => 'Create Sales Adjustments',
            'sales.adjustments.update' => 'Update Sales Adjustments',
            'sales.adjustments.submit' => 'Submit Sales Adjustments',
            'sales.adjustments.approve' => 'Approve Sales Adjustments',
            'sales.adjustments.post' => 'Post Sales Adjustments',
            'sales.adjustments.reverse' => 'Reverse Sales Adjustments',
        ];

        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'sales', 'created_at' => now(), 'updated_at' => now()]);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'business_owner')->pluck('id') as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $memberViewKeys = ['sales.returns.view', 'sales.adjustments.view'];
        $memberIds = DB::table('permissions')->whereIn('key', $memberViewKeys)->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach ($memberIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $administratorKeys = ['sales.returns.view', 'sales.returns.create', 'sales.returns.update', 'sales.returns.submit', 'sales.returns.review', 'sales.adjustments.view', 'sales.adjustments.create', 'sales.adjustments.update', 'sales.adjustments.submit'];
        $administratorIds = DB::table('permissions')->whereIn('key', $administratorKeys)->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'administrator')->pluck('id') as $roleId) {
            foreach ($administratorIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_status_check CHECK (status IN ('draft','for_approval','approved','posted','cancelled','reversed','failed'))");
            DB::statement("ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_type_check CHECK (refund_status IN ('not_required','pending_mds500','reversed'))");
            DB::statement("ALTER TABLE sales_adjustments ADD CONSTRAINT sales_adjustments_type_check CHECK (adjustment_type IN ('debit','credit'))");
            DB::statement("ALTER TABLE sales_adjustments ADD CONSTRAINT sales_adjustments_status_check CHECK (status IN ('draft','for_approval','approved','posted','cancelled','reversed','failed'))");
            DB::statement("ALTER TABLE sales_adjustments ADD CONSTRAINT sales_adjustments_refund_check CHECK (refund_status IN ('not_required','pending_mds500','reversed'))");
            DB::statement('ALTER TABLE sales_returns ADD CONSTRAINT sales_returns_amounts_check CHECK (subtotal >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND customer_credit_amount >= 0)');
            DB::statement('ALTER TABLE sales_return_lines ADD CONSTRAINT sales_return_lines_amounts_check CHECK (original_quantity > 0 AND previously_returned_quantity >= 0 AND quantity > 0 AND unit_price >= 0 AND taxable_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0)');
            DB::statement('ALTER TABLE sales_adjustments ADD CONSTRAINT sales_adjustments_amounts_check CHECK (amount >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND customer_credit_amount >= 0)');
            DB::statement('ALTER TABLE sales_adjustment_lines ADD CONSTRAINT sales_adjustment_lines_amounts_check CHECK (unit_amount >= 0 AND amount >= 0 AND tax_amount >= 0 AND total_amount >= 0)');
        }
    }

    public function down(): void
    {
        $keys = ['sales.reverse', 'sales.returns.view', 'sales.returns.create', 'sales.returns.update', 'sales.returns.submit', 'sales.returns.review', 'sales.returns.approve', 'sales.returns.post', 'sales.returns.reverse', 'sales.adjustments.view', 'sales.adjustments.create', 'sales.adjustments.update', 'sales.adjustments.submit', 'sales.adjustments.approve', 'sales.adjustments.post', 'sales.adjustments.reverse'];
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('sales_receivable_effects');
        Schema::dropIfExists('sales_adjustment_status_histories');
        Schema::dropIfExists('sales_adjustment_lines');
        Schema::dropIfExists('sales_adjustments');
        Schema::dropIfExists('sales_return_status_histories');
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
        Schema::table('billing_statements', function (Blueprint $table) {
            if (Schema::hasColumn('billing_statements', 'source_snapshot')) {
                $table->dropColumn('source_snapshot');
            }
        });
    }
};
