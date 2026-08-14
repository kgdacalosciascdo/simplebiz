<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_instruction_sources', function (Blueprint $table) {
            $table->foreignUuid('expense_obligation_id')->nullable()->after('payable_open_item_id')->constrained('expense_obligations')->restrictOnDelete();
            $table->index(['company_id', 'expense_obligation_id']);
        });
        Schema::table('payment_instruction_sources', function (Blueprint $table) {
            $table->foreignUuid('payable_open_item_id')->nullable()->change();
            $table->unique(['payment_instruction_id', 'expense_obligation_id']);
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignUuid('expense_obligation_id')->nullable()->after('payable_open_item_id')->constrained('expense_obligations')->restrictOnDelete();
            $table->index(['company_id', 'expense_obligation_id', 'status']);
            $table->foreignUuid('payable_open_item_id')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_instruction_sources ADD CONSTRAINT payment_instruction_sources_one_owner CHECK ((payable_open_item_id IS NOT NULL AND expense_obligation_id IS NULL) OR (payable_open_item_id IS NULL AND expense_obligation_id IS NOT NULL))');
            DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_one_owner CHECK ((payable_open_item_id IS NOT NULL AND expense_obligation_id IS NULL) OR (payable_open_item_id IS NULL AND expense_obligation_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['expense_obligation_id']);
            $table->dropIndex(['company_id', 'expense_obligation_id', 'status']);
            $table->dropColumn('expense_obligation_id');
        });
        Schema::table('payment_instruction_sources', function (Blueprint $table) {
            $table->dropUnique(['payment_instruction_id', 'expense_obligation_id']);
            $table->dropForeign(['expense_obligation_id']);
            $table->dropIndex(['company_id', 'expense_obligation_id']);
            $table->dropColumn('expense_obligation_id');
        });
    }
};
