<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number', 60);
            $table->string('receipt_type', 40)->default('customer_collection');
            $table->date('receipt_date');
            $table->foreignUuid('customer_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('source_sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->string('payer_name_snapshot', 180)->nullable();
            $table->string('external_reference', 160)->nullable();
            $table->string('customer_reference', 160)->nullable();
            $table->decimal('amount', 20, 6);
            $table->decimal('tender_total', 20, 6)->default(0);
            $table->decimal('applied_total', 20, 6)->default(0);
            $table->decimal('unapplied_amount', 20, 6)->default(0);
            $table->decimal('reversed_amount', 20, 6)->default(0);
            $table->string('status', 24)->default('draft');
            $table->string('application_status', 24)->default('unapplied');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('correction_reason')->nullable();
            $table->foreignUuid('business_transaction_id')->nullable()->constrained('business_transactions')->restrictOnDelete();
            $table->foreignUuid('accounting_transaction_id')->nullable()->constrained('accounting_transactions')->restrictOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'receipt_number']);
            $table->index(['company_id', 'customer_id', 'receipt_date']);
            $table->index(['company_id', 'status', 'application_status']);
        });

        Schema::create('receipt_tenders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('receipt_id')->constrained('receipts')->cascadeOnDelete();
            $table->foreignUuid('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('amount', 20, 6);
            $table->string('instrument_status', 24)->default('not_applicable');
            $table->string('clearing_status', 24)->default('pending');
            $table->string('external_reference', 160)->nullable();
            $table->string('instrument_reference', 160)->nullable();
            $table->date('value_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'receipt_id']);
            $table->index(['company_id', 'payment_method_id', 'external_reference']);
        });

        Schema::create('payment_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('receipt_id')->constrained('receipts')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('receivable_open_item_id')->constrained('receivable_open_items')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('amount', 20, 6);
            $table->date('application_date');
            $table->string('status', 24)->default('unapplied');
            $table->unsignedInteger('version')->default(1);
            // Kept as an application link rather than a database FK so a reversal
            // can remain durable even when the original is retained historically.
            $table->uuid('original_application_id')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'customer_id', 'application_date']);
            $table->index(['company_id', 'receipt_id', 'status']);
            $table->index(['company_id', 'receivable_open_item_id', 'status']);
            $table->index(['company_id', 'original_application_id']);
        });

        Schema::create('customer_unapplied_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('receipt_id')->unique()->constrained('receipts')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignUuid('currency_id')->constrained('reference_currencies')->restrictOnDelete();
            $table->decimal('original_amount', 20, 6);
            $table->decimal('applied_later_amount', 20, 6)->default(0);
            $table->decimal('available_amount', 20, 6);
            $table->string('status', 24)->default('available');
            $table->date('received_date');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['company_id', 'customer_id', 'status']);
        });

        Schema::create('receipt_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('receipt_id')->constrained('receipts')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'receipt_id', 'created_at']);
        });

        Schema::create('payment_application_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_application_id')->constrained('payment_applications')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'payment_application_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_status_check CHECK (status IN ('draft','for_approval','approved','posted','voided','reversed','failed'))");
            DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_type_check CHECK (receipt_type IN ('customer_collection','advance_unapplied_customer_receipt','paid_now_sale_receipt'))");
            DB::statement('ALTER TABLE receipts ADD CONSTRAINT receipts_amounts_check CHECK (amount > 0 AND tender_total >= 0 AND applied_total >= 0 AND unapplied_amount >= 0 AND reversed_amount >= 0)');
            DB::statement("ALTER TABLE payment_applications ADD CONSTRAINT payment_applications_status_check CHECK (status IN ('unapplied','partially_applied','fully_applied','reversed'))");
            DB::statement('ALTER TABLE payment_applications ADD CONSTRAINT payment_applications_amount_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE receipt_tenders ADD CONSTRAINT receipt_tenders_amount_check CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_application_histories');
        Schema::dropIfExists('receipt_status_histories');
        Schema::dropIfExists('customer_unapplied_receipts');
        Schema::dropIfExists('payment_applications');
        Schema::dropIfExists('receipt_tenders');
        Schema::dropIfExists('receipts');
    }
};
