<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('sale_number', 60);
            $table->string('sale_type', 24);
            $table->string('payment_basis', 16);
            $table->date('sale_date');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->foreignUuid('payment_term_id')->nullable()->constrained('payment_terms')->restrictOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('return_status', 24)->default('not_returned');
            $table->string('settlement_status', 24)->default('not_applicable');
            $table->string('due_status', 24)->default('no_due_date');
            $table->string('dispute_status', 24)->default('not_disputed');
            $table->string('customer_reference', 160)->nullable();
            $table->string('channel', 40)->nullable();
            $table->text('notes')->nullable();
            $table->string('document_discount_type', 16)->nullable();
            $table->decimal('document_discount_value', 20, 6)->default(0);
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('line_discount_total', 20, 6)->default(0);
            $table->decimal('document_discount_total', 20, 6)->default(0);
            $table->decimal('taxable_amount', 20, 6)->default(0);
            $table->decimal('tax_total', 20, 6)->default(0);
            $table->decimal('total', 20, 6)->default(0);
            $table->decimal('paid_amount', 20, 6)->default(0);
            $table->decimal('receivable_amount', 20, 6)->default(0);
            $table->decimal('remaining_amount', 20, 6)->default(0);
            $table->string('blocked_code', 80)->nullable();
            $table->text('blocked_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignUuid('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'sale_number']);
            $table->index(['company_id', 'customer_id', 'sale_date']);
            $table->index(['company_id', 'status', 'sale_date']);
            $table->index(['company_id', 'payment_basis', 'status']);
            $table->index(['company_id', 'due_date', 'settlement_status']);
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->string('item_type', 20);
            $table->string('item_code_snapshot', 80);
            $table->string('description_snapshot', 240);
            $table->string('unit_code', 40)->nullable();
            $table->string('unit_name', 120)->nullable();
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 20, 6);
            $table->decimal('gross_amount', 20, 6)->default(0);
            $table->string('discount_type', 16)->nullable();
            $table->decimal('discount_value', 20, 6)->default(0);
            $table->decimal('discount_amount', 20, 6)->default(0);
            $table->foreignUuid('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->string('tax_code_snapshot', 80)->nullable();
            $table->string('tax_basis', 16)->nullable();
            $table->decimal('tax_rate', 20, 6)->default(0);
            $table->decimal('taxable_amount', 20, 6)->default(0);
            $table->decimal('tax_amount', 20, 6)->default(0);
            $table->decimal('net_amount', 20, 6)->default(0);
            $table->boolean('stock_managed_snapshot')->default(false);
            $table->boolean('non_stock_snapshot')->default(false);
            $table->boolean('service_snapshot')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['company_id', 'sale_id']);
            $table->index(['company_id', 'product_service_id']);
        });

        Schema::create('receivable_open_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('source_sale_id')->constrained('sales')->restrictOnDelete();
            $table->string('source_document_number', 60);
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('original_amount', 20, 6);
            $table->decimal('applied_amount', 20, 6)->default(0);
            $table->decimal('debit_adjustment_amount', 20, 6)->default(0);
            $table->decimal('credit_adjustment_amount', 20, 6)->default(0);
            $table->decimal('return_amount', 20, 6)->default(0);
            $table->decimal('write_off_amount', 20, 6)->default(0);
            $table->decimal('remaining_amount', 20, 6);
            $table->date('due_date')->nullable();
            $table->string('settlement_status', 24)->default('unpaid');
            $table->string('due_status', 24)->default('no_due_date');
            $table->string('dispute_status', 24)->default('not_disputed');
            $table->timestamp('last_calculated_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'source_sale_id']);
            $table->index(['company_id', 'customer_id', 'due_date']);
            $table->index(['company_id', 'settlement_status', 'due_status']);
        });

        Schema::create('billing_statements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('statement_number', 60);
            $table->foreignUuid('customer_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->date('statement_date');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->timestamp('as_of_at');
            $table->string('status', 24)->default('draft');
            $table->decimal('opening_balance', 20, 6)->default(0);
            $table->decimal('period_charges', 20, 6)->default(0);
            $table->decimal('period_credits', 20, 6)->default(0);
            $table->decimal('period_applications', 20, 6)->default(0);
            $table->decimal('ending_balance', 20, 6)->default(0);
            $table->json('filters')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'statement_number']);
            $table->index(['company_id', 'customer_id', 'statement_date']);
        });

        Schema::create('billing_statement_open_items', function (Blueprint $table) {
            $table->foreignUuid('billing_statement_id')->constrained('billing_statements')->cascadeOnDelete();
            $table->foreignUuid('receivable_open_item_id')->constrained('receivable_open_items')->restrictOnDelete();
            $table->foreignUuid('source_sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->decimal('included_amount', 20, 6);
            $table->timestamps();
            $table->primary(['billing_statement_id', 'receivable_open_item_id']);
        });

        Schema::create('sale_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'sale_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_type_check CHECK (sale_type IN ('credit_sale','cash_sale'))");
            DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_basis_check CHECK (payment_basis IN ('credit','cash'))");
            DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_status_check CHECK (status IN ('draft','for_approval','approved','posted','cancelled','reversed','failed'))");
            DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_amounts_check CHECK (subtotal >= 0 AND line_discount_total >= 0 AND document_discount_total >= 0 AND taxable_amount >= 0 AND tax_total >= 0 AND total >= 0 AND paid_amount >= 0 AND receivable_amount >= 0 AND remaining_amount >= 0)');
            DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND gross_amount >= 0 AND discount_value >= 0 AND discount_amount >= 0 AND tax_rate >= 0 AND taxable_amount >= 0 AND tax_amount >= 0 AND net_amount >= 0)');
            DB::statement('ALTER TABLE receivable_open_items ADD CONSTRAINT receivable_open_items_amounts_check CHECK (original_amount >= 0 AND applied_amount >= 0 AND debit_adjustment_amount >= 0 AND credit_adjustment_amount >= 0 AND return_amount >= 0 AND write_off_amount >= 0 AND remaining_amount >= 0)');
            DB::statement("ALTER TABLE billing_statements ADD CONSTRAINT billing_statements_status_check CHECK (status IN ('draft','generated','issued','superseded','cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_status_histories');
        Schema::dropIfExists('billing_statement_open_items');
        Schema::dropIfExists('billing_statements');
        Schema::dropIfExists('receivable_open_items');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
    }
};
