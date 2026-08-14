<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('adjustment_number', 80);
            $table->string('source_type', 64)->default('direct');
            $table->string('source_reference', 180)->nullable();
            $table->date('business_date');
            $table->foreignUuid('reason_code_id')->nullable()->constrained('reason_codes')->restrictOnDelete();
            $table->string('evidence_reference', 255)->nullable();
            $table->text('explanation');
            $table->string('status', 24)->default('draft');
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
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->uuid('original_adjustment_id')->nullable();
            $table->uuid('reversal_adjustment_id')->nullable();
            $table->uuid('stock_count_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'adjustment_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'status', 'business_date']);
        });

        Schema::create('inventory_adjustment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventory_adjustment_id')->constrained('inventory_adjustments')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->string('direction', 8);
            $table->decimal('expected_quantity', 20, 6)->nullable();
            $table->decimal('resulting_quantity', 20, 6)->nullable();
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();
            $table->string('currency_code', 12)->nullable();
            $table->string('cost_source', 80)->nullable();
            $table->foreignUuid('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->uuid('stock_count_item_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'inventory_adjustment_id']);
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('count_number', 80);
            $table->date('business_date');
            $table->string('mode', 16)->default('spot');
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $table->boolean('blind')->default(false);
            $table->string('status', 24)->default('planned');
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamp('cutoff_at')->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->text('explanation')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'count_number']);
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'status', 'business_date']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_count_id')->constrained('stock_counts')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('expected_quantity', 20, 6)->default(0);
            $table->decimal('counted_quantity', 20, 6)->nullable();
            $table->decimal('variance_quantity', 20, 6)->nullable();
            $table->string('variance_status', 24)->default('pending');
            $table->boolean('recount_required')->default(false);
            $table->foreignUuid('latest_entry_id')->nullable();
            $table->foreignUuid('adjustment_id')->nullable();
            $table->string('product_code_snapshot', 80);
            $table->string('product_name_snapshot', 180);
            $table->string('unit_code_snapshot', 40);
            $table->string('unit_name_snapshot', 120);
            $table->timestamps();
            $table->unique(['stock_count_id', 'product_service_id', 'warehouse_id', 'stock_location_id'], 'stock_count_scope_item_unique');
            $table->index(['company_id', 'stock_count_id']);
        });

        Schema::create('stock_count_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_count_id')->constrained('stock_counts')->restrictOnDelete();
            $table->foreignUuid('stock_count_item_id')->constrained('stock_count_items')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->boolean('is_recount')->default(false);
            $table->decimal('counted_quantity', 20, 6);
            $table->text('evidence_reference')->nullable();
            $table->text('explanation')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['stock_count_item_id', 'sequence']);
            $table->index(['company_id', 'stock_count_id']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reservation_number', 80);
            $table->string('source_type', 100);
            $table->string('source_id', 120);
            $table->string('source_line_id', 120);
            $table->string('source_document_number', 80)->nullable();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignUuid('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('consumed_quantity', 20, 6)->default(0);
            $table->decimal('released_quantity', 20, 6)->default(0);
            $table->string('status', 24)->default('requested');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'reservation_number']);
            $table->unique(['company_id', 'source_type', 'source_id', 'source_line_id'], 'stock_reservation_source_unique');
            $table->unique(['company_id', 'idempotency_identity']);
            $table->index(['company_id', 'product_service_id', 'warehouse_id', 'stock_location_id', 'status']);
        });

        Schema::create('stock_reservation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_reservation_id')->constrained('stock_reservations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 24);
            $table->decimal('quantity', 20, 6);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'stock_reservation_id']);
        });

        Schema::create('inventory_reorder_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $table->decimal('reorder_point', 20, 6);
            $table->decimal('minimum_quantity', 20, 6)->default(0);
            $table->decimal('target_quantity', 20, 6)->nullable();
            $table->decimal('suggested_quantity', 20, 6)->nullable();
            $table->string('status', 24)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['company_id', 'product_service_id', 'status']);
            $table->index(['company_id', 'warehouse_id', 'stock_location_id']);
        });

        Schema::create('inventory_valuation_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignUuid('product_service_id')->constrained('products_services')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 6);
            $table->decimal('total_cost', 20, 6);
            $table->string('currency_code', 12);
            $table->string('cost_source', 80);
            $table->timestamp('effective_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'stock_movement_id']);
            $table->index(['company_id', 'product_service_id', 'effective_at']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('total_cost', 20, 6)->nullable();
            $table->string('currency_code', 12)->nullable();
            $table->string('cost_source', 80)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_adjustment_lines ADD CONSTRAINT inventory_adjustment_lines_quantity_positive CHECK (quantity > 0)');
            DB::statement("ALTER TABLE inventory_adjustment_lines ADD CONSTRAINT inventory_adjustment_lines_direction_check CHECK (direction IN ('in','out'))");
            DB::statement('ALTER TABLE stock_count_entries ADD CONSTRAINT stock_count_entries_quantity_nonnegative CHECK (counted_quantity >= 0)');
            DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_consumed_valid CHECK (consumed_quantity >= 0 AND released_quantity >= 0 AND consumed_quantity + released_quantity <= quantity)');
        }

        $permissions = [
            'inventory.adjustments.view' => 'View Inventory Adjustments',
            'inventory.adjustments.create' => 'Create Inventory Adjustments',
            'inventory.adjustments.update' => 'Update Inventory Adjustments',
            'inventory.adjustments.submit' => 'Submit Inventory Adjustments',
            'inventory.adjustments.review' => 'Review Inventory Adjustments',
            'inventory.adjustments.approve' => 'Approve Inventory Adjustments',
            'inventory.adjustments.post' => 'Post Inventory Adjustments',
            'inventory.adjustments.reverse' => 'Reverse Inventory Adjustments',
            'inventory.counts.view' => 'View Physical Counts',
            'inventory.counts.create' => 'Create Physical Counts',
            'inventory.counts.start' => 'Start Physical Counts',
            'inventory.counts.entries' => 'Enter Physical Counts',
            'inventory.counts.recount' => 'Recount Physical Counts',
            'inventory.counts.review' => 'Review Physical Counts',
            'inventory.counts.approve' => 'Approve Physical Counts',
            'inventory.counts.post' => 'Post Physical Counts',
            'inventory.counts.close' => 'Close Physical Counts',
            'inventory.counts.reopen' => 'Reopen Physical Counts',
            'inventory.counts.cancel' => 'Cancel Physical Counts',
            'inventory.reservations.view' => 'View Stock Reservations',
            'inventory.reservations.create' => 'Create Stock Reservations',
            'inventory.reservations.consume' => 'Consume Stock Reservations',
            'inventory.reservations.release' => 'Release Stock Reservations',
            'inventory.reservations.expire' => 'Expire Stock Reservations',
            'inventory.reorder.view' => 'View Inventory Reorder Rules',
            'inventory.reorder.manage' => 'Manage Inventory Reorder Rules',
            'inventory.stock-card.view' => 'View Inventory Stock Card',
            'inventory.reports.view' => 'View Inventory Reports',
            'inventory.valuation.view' => 'View Inventory Valuation',
            'inventory.cost.view' => 'View Inventory Cost Fields',
            'inventory.barcode.lookup' => 'Lookup Inventory by Barcode',
        ];
        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'inventory', 'created_at' => now(), 'updated_at' => now()]);
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
        $ids = DB::table('permissions')->where('module', 'inventory')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'total_cost', 'currency_code', 'cost_source']);
        });
        Schema::dropIfExists('inventory_valuation_records');
        Schema::dropIfExists('inventory_reorder_rules');
        Schema::dropIfExists('stock_reservation_events');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_count_entries');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('inventory_adjustment_lines');
        Schema::dropIfExists('inventory_adjustments');
    }
};
