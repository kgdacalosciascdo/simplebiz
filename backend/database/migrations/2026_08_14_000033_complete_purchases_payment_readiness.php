<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payable_open_items', function (Blueprint $table) {
            $table->foreignUuid('branch_id')->nullable()->after('supplier_id')->constrained('branches')->restrictOnDelete();
            $table->index(['company_id', 'branch_id']);
        });

        DB::table('payable_open_items')->whereNull('branch_id')->get(['id', 'source_supplier_invoice_id'])->each(function (object $payable): void {
            $branchId = DB::table('supplier_invoices')->where('id', $payable->source_supplier_invoice_id)->value('branch_id');
            if ($branchId) {
                DB::table('payable_open_items')->where('id', $payable->id)->update(['branch_id' => $branchId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payable_open_items', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['company_id', 'branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
