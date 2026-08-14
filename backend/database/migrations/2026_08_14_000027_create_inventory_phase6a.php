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
            $table->foreignUuid('warehouse_id')->nullable()->after('product_service_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->nullable()->after('warehouse_id')->constrained('stock_locations')->restrictOnDelete();
            $table->index(['company_id', 'warehouse_id', 'stock_location_id']);
        });

        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('on_hand', 20, 6)->default(0);
            $table->decimal('reserved', 20, 6)->default(0);
            $table->decimal('incoming', 20, 6)->default(0);
            $table->decimal('outgoing', 20, 6)->default(0);
            $table->decimal('in_transit', 20, 6)->default(0);
            $table->decimal('held', 20, 6)->default(0);
            $table->decimal('count_frozen', 20, 6)->default(0);
            $table->string('status', 24)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'product_service_id', 'warehouse_id', 'stock_location_id', 'unit_of_measure_id'], 'inventory_balances_scope_unique');
            $table->index(['company_id', 'product_service_id']);
            $table->index(['company_id', 'warehouse_id', 'stock_location_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->string('movement_type', 32);
            $table->string('direction', 8);
            $table->decimal('quantity', 20, 6);
            $table->date('business_date');
            $table->timestamp('posted_at');
            $table->string('source_module', 48);
            $table->string('source_type', 100);
            $table->string('source_id', 120);
            $table->string('source_line_id', 120)->nullable();
            $table->string('source_document_number', 80)->nullable();
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->text('explanation')->nullable();
            $table->uuid('original_movement_id')->nullable();
            $table->uuid('reversal_movement_id')->nullable();
            $table->string('transfer_id', 120)->nullable();
            $table->string('status', 24)->default('posted');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'product_service_id', 'business_date']);
            $table->index(['company_id', 'warehouse_id', 'stock_location_id', 'business_date']);
            $table->index(['company_id', 'movement_type', 'status']);
            $table->index(['company_id', 'source_module', 'source_id']);
            $table->unique(['company_id', 'source_module', 'source_type', 'source_id', 'source_line_id', 'movement_type'], 'stock_movements_source_effect_unique');
            $table->unique(['company_id', 'idempotency_identity'], 'stock_movements_idempotency_unique');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('original_movement_id')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreign('reversal_movement_id')->references('id')->on('stock_movements')->restrictOnDelete();
        });

        Schema::create('opening_stock_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_number', 80);
            $table->date('business_date');
            $table->string('source_reference', 180)->nullable();
            $table->text('explanation');
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'document_number']);
            $table->unique(['company_id', 'idempotency_identity']);
        });

        Schema::create('opening_stock_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('opening_stock_document_id')->constrained('opening_stock_documents')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->foreignUuid('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'opening_stock_document_id']);
        });

        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_number', 80);
            $table->string('source_type', 48)->default('direct');
            $table->string('source_reference', 180)->nullable();
            $table->date('business_date');
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->text('explanation');
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'document_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'status', 'business_date']);
        });

        Schema::create('stock_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_receipt_id')->constrained('stock_receipts')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->foreignUuid('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'stock_receipt_id']);
        });

        Schema::create('stock_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_number', 80);
            $table->string('source_type', 48)->default('direct');
            $table->string('source_reference', 180)->nullable();
            $table->date('business_date');
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->text('explanation');
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'document_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'status', 'business_date']);
        });

        Schema::create('stock_issue_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_issue_id')->constrained('stock_issues')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->foreignUuid('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'stock_issue_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_number', 80);
            $table->foreignUuid('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('source_stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignUuid('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('destination_stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->date('business_date');
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->text('explanation');
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'document_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'status', 'business_date']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->foreignUuid('source_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignUuid('destination_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'stock_transfer_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_balances_nonnegative CHECK (on_hand >= 0 AND reserved >= 0 AND incoming >= 0 AND outgoing >= 0 AND in_transit >= 0 AND held >= 0 AND count_frozen >= 0)');
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_positive CHECK (quantity > 0)');
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_direction_check CHECK (direction IN ('in','out'))");
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_status_check CHECK (status IN ('posted','reversed'))");
            DB::statement('ALTER TABLE opening_stock_lines ADD CONSTRAINT opening_stock_lines_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE stock_receipt_lines ADD CONSTRAINT stock_receipt_lines_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE stock_issue_lines ADD CONSTRAINT stock_issue_lines_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_distinct_locations CHECK (source_stock_location_id <> destination_stock_location_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_issue_lines');
        Schema::dropIfExists('stock_issues');
        Schema::dropIfExists('stock_receipt_lines');
        Schema::dropIfExists('stock_receipts');
        Schema::dropIfExists('opening_stock_lines');
        Schema::dropIfExists('opening_stock_documents');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_balances');
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropForeign(['stock_location_id']);
            $table->dropColumn(['warehouse_id', 'stock_location_id']);
        });
    }
};
