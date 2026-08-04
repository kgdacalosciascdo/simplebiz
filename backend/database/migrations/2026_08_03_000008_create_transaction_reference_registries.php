<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->uuid('default_currency_id')->nullable()->index();
            $table->uuid('default_branch_id')->nullable()->index();
            $table->uuid('default_payment_term_id')->nullable()->index();
            $table->uuid('default_payment_method_id')->nullable()->index();
            $table->uuid('default_warehouse_id')->nullable()->index();
            $table->uuid('default_stock_location_id')->nullable()->index();
            $table->uuid('default_expense_category_id')->nullable()->index();
            $table->uuid('default_expense_account_title_id')->nullable()->index();
        });

        Schema::create('reference_currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 3);
            $table->string('normalized_code', 3);
            $table->string('name', 120);
            $table->string('symbol', 12)->nullable();
            $table->unsignedSmallInteger('decimal_precision')->default(2);
            $table->string('status', 24)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status_reason')->nullable();
            $table->boolean('system_standard')->default(false);
            $table->boolean('locked')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'normalized_code']);
            $table->index(['company_id', 'status', 'effective_from']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 160);
            $table->string('method_class', 32);
            $table->text('description')->nullable();
            $table->boolean('requires_external_reference')->default(false);
            $table->boolean('requires_account_selection')->default(false);
            $table->boolean('supports_incoming')->default(false);
            $table->boolean('supports_outgoing')->default(false);
            $table->string('clearing_behavior', 32)->default('direct');
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
            $table->index(['company_id', 'status', 'method_class']);
        });

        Schema::create('payment_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('term_type', 24);
            $table->unsignedInteger('due_days')->default(0);
            $table->boolean('end_of_month')->default(false);
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
            $table->index(['company_id', 'status', 'term_type']);
        });

        Schema::create('tax_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('tax_type', 32);
            $table->decimal('rate', 9, 4)->default(0);
            $table->string('basis', 16)->default('exclusive');
            $table->boolean('recoverable')->default(false);
            $table->boolean('withholding')->default(false);
            $table->string('jurisdiction', 80)->nullable();
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
            $table->index(['company_id', 'status', 'tax_type']);
        });

        Schema::create('account_titles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('classification', 16);
            $table->string('normal_balance', 8);
            $table->string('account_subtype', 80)->nullable();
            $table->boolean('posting_eligible')->default(true);
            $table->boolean('system_standard')->default(false);
            $table->boolean('locked')->default(false);
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
            $table->index(['company_id', 'status', 'classification']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->uuid('account_title_id');
            $table->string('reporting_tag', 80)->nullable();
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
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'account_title_id']);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('branch_type', 40)->nullable();
            $table->string('address_line1', 180)->nullable();
            $table->string('address_line2', 180)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 40)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('contact_name', 160)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 60)->nullable();
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
            $table->index(['company_id', 'status']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->uuid('branch_id')->nullable();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('warehouse_type', 40)->nullable();
            $table->string('address_line1', 180)->nullable();
            $table->string('address_line2', 180)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 40)->nullable();
            $table->string('country', 2)->nullable();
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
            $table->index(['company_id', 'branch_id', 'status']);
        });

        Schema::create('stock_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->uuid('warehouse_id');
            $table->uuid('parent_id')->nullable();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 160);
            $table->string('location_type', 40)->default('storage');
            $table->text('description')->nullable();
            $table->boolean('sellable')->default(false);
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
            $table->unique(['company_id', 'warehouse_id', 'normalized_code']);
            $table->index(['company_id', 'warehouse_id', 'status']);
            $table->index(['company_id', 'parent_id']);
        });

        Schema::create('reason_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('normalized_code', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('domain', 48);
            $table->boolean('requires_explanation')->default(false);
            $table->boolean('requires_evidence')->default(false);
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
            $table->unique(['company_id', 'domain', 'normalized_code']);
            $table->index(['company_id', 'domain', 'status']);
        });

        foreach (['reference_currencies', 'payment_methods', 'payment_terms', 'tax_codes', 'account_titles', 'expense_categories', 'branches', 'warehouses', 'stock_locations', 'reason_codes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('source_channel', 48)->default('api');
                $table->uuid('correlation_id')->nullable()->index();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reference_currencies ADD CONSTRAINT reference_currencies_precision CHECK (decimal_precision BETWEEN 0 AND 12)');
            DB::statement('ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_direction CHECK (supports_incoming = true OR supports_outgoing = true)');
            DB::statement('ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_due_days CHECK (due_days >= 0)');
            DB::statement("ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_type CHECK ((term_type = 'immediate' AND due_days = 0) OR term_type IN ('due_days', 'due_date'))");
            DB::statement('ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_rate CHECK (rate BETWEEN 0 AND 100)');
            DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_basis CHECK (basis IN ('inclusive', 'exclusive'))");
            DB::statement("ALTER TABLE account_titles ADD CONSTRAINT account_titles_classification CHECK (classification IN ('asset', 'liability', 'equity', 'income', 'expense'))");
            DB::statement("ALTER TABLE account_titles ADD CONSTRAINT account_titles_normal_balance CHECK (normal_balance IN ('debit', 'credit'))");
            DB::statement('ALTER TABLE reference_currencies ADD CONSTRAINT reference_currencies_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE account_titles ADD CONSTRAINT account_titles_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE expense_categories ADD CONSTRAINT expense_categories_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE branches ADD CONSTRAINT branches_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE warehouses ADD CONSTRAINT warehouses_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE stock_locations ADD CONSTRAINT stock_locations_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE reason_codes ADD CONSTRAINT reason_codes_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reason_codes');
        Schema::dropIfExists('stock_locations');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('account_titles');
        Schema::dropIfExists('tax_codes');
        Schema::dropIfExists('payment_terms');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('reference_currencies');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['default_currency_id', 'default_branch_id', 'default_payment_term_id', 'default_payment_method_id', 'default_warehouse_id', 'default_stock_location_id', 'default_expense_category_id', 'default_expense_account_title_id']);
        });
    }
};
