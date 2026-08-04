<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('party_type', 24)->default('organization');
            $table->string('official_name', 180);
            $table->string('display_name', 180);
            $table->string('trade_name', 180)->nullable();
            $table->string('tax_reference', 120)->nullable();
            $table->string('primary_email', 255)->nullable();
            $table->string('primary_phone', 60)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status_reason')->nullable();
            $table->string('source_channel', 48)->default('api');
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'normalized_code']);
            $table->index(['company_id', 'status', 'effective_from']);
            $table->index(['company_id', 'normalized_code']);
            $table->index(['company_id', 'official_name']);
        });

        Schema::create('business_partner_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('role', 24);
            $table->string('status', 24)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status_reason')->nullable();
            $table->timestamps();
            $table->unique(['business_partner_id', 'role']);
            $table->index(['company_id', 'role', 'status']);
        });

        Schema::create('business_partner_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('contact_type', 32)->default('general');
            $table->string('contact_name', 160);
            $table->string('position', 120)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('mobile', 60)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 24)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'business_partner_id', 'status']);
            $table->index(['company_id', 'email']);
        });

        Schema::create('business_partner_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('address_type', 32)->default('general');
            $table->string('line1', 180);
            $table->string('line2', 180)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 40)->nullable();
            $table->string('country', 2)->default('PH');
            $table->boolean('is_primary_billing')->default(false);
            $table->boolean('is_primary_shipping')->default(false);
            $table->string('status', 24)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'business_partner_id', 'status']);
        });

        Schema::create('registry_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            // Parent hierarchy is validated in the service so circular references can be rejected safely.
            $table->uuid('parent_id')->nullable()->index();
            $table->string('applicability', 16)->default('both');
            $table->unsignedInteger('display_order')->default(0);
            $table->string('status', 24)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'normalized_code']);
            $table->index(['company_id', 'status', 'applicability']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('normalized_code', 40);
            $table->string('name', 120);
            $table->string('symbol', 24)->nullable();
            $table->string('unit_type', 40)->default('quantity');
            $table->unsignedSmallInteger('decimal_precision')->default(0);
            $table->boolean('allows_fractional')->default(false);
            $table->string('status', 24)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'normalized_code']);
            $table->index(['company_id', 'status', 'unit_type']);
        });

        Schema::create('products_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('record_type', 16);
            $table->foreignUuid('category_id')->nullable()->constrained('registry_categories')->nullOnDelete();
            $table->foreignUuid('base_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->boolean('sellable')->default(true);
            $table->boolean('purchasable')->default(false);
            $table->boolean('stock_managed')->default(false);
            $table->boolean('non_stock')->default(true);
            $table->decimal('standard_selling_price', 19, 4)->nullable();
            $table->decimal('standard_purchase_price', 19, 4)->nullable();
            $table->string('tax_reference', 120)->nullable();
            $table->string('barcode', 80)->nullable();
            $table->string('status', 24)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status_reason')->nullable();
            $table->string('source_channel', 48)->default('api');
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'normalized_code']);
            $table->index(['company_id', 'record_type', 'status']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'barcode']);
        });

        Schema::create('registry_external_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('registry_type', 40);
            $table->uuid('record_id');
            $table->string('identifier_type', 60);
            $table->string('value', 180);
            $table->string('normalized_value', 180);
            $table->string('source_system', 80)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'registry_type', 'identifier_type', 'normalized_value']);
            $table->index(['company_id', 'registry_type', 'record_id']);
        });

        Schema::create('registry_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('registry_type', 40);
            $table->uuid('record_id');
            $table->string('action', 80);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_channel', 48)->default('api');
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'registry_type', 'record_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE business_partners ADD CONSTRAINT business_partners_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE business_partner_roles ADD CONSTRAINT business_partner_roles_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE business_partner_contacts ADD CONSTRAINT business_partner_contacts_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE business_partner_addresses ADD CONSTRAINT business_partner_addresses_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE registry_categories ADD CONSTRAINT registry_categories_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE units_of_measure ADD CONSTRAINT units_of_measure_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE products_services ADD CONSTRAINT products_services_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE products_services ADD CONSTRAINT products_services_type_rules CHECK (record_type = 'product' OR (record_type = 'service' AND stock_managed = false))");
            DB::statement("ALTER TABLE products_services ADD CONSTRAINT products_services_stock_unit_rule CHECK (stock_managed = false OR (record_type = 'product' AND base_unit_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_history');
        Schema::dropIfExists('registry_external_identifiers');
        Schema::dropIfExists('products_services');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('registry_categories');
        Schema::dropIfExists('business_partner_addresses');
        Schema::dropIfExists('business_partner_contacts');
        Schema::dropIfExists('business_partner_roles');
        Schema::dropIfExists('business_partners');
    }
};
