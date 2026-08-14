<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_remittances', function (Blueprint $table) {
            $table->foreignUuid('source_cash_account_id')->nullable()->after('currency_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignUuid('cash_transfer_document_id')->nullable()->after('destination_cash_account_id')->unique()->constrained('cash_transfer_documents')->restrictOnDelete();
            $table->timestamp('transfer_posted_at')->nullable()->after('accepted_at');
            $table->foreignId('reversed_by')->nullable()->after('transfer_posted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->text('reversal_reason')->nullable()->after('reversed_at');
            $table->index(['company_id', 'source_cash_account_id', 'destination_cash_account_id']);
        });

        $permission = DB::table('permissions')->where('key', 'collections.remittance.reverse')->value('id');
        if (! $permission) {
            $permission = DB::table('permissions')->insertGetId(['key' => 'collections.remittance.reverse', 'name' => 'Reverse Cash Remittances', 'module' => 'collections', 'created_at' => now(), 'updated_at' => now()]);
        }
        $roleIds = DB::table('roles')->whereIn('system_key', ['owner', 'administrator'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        Schema::table('cash_remittances', function (Blueprint $table) {
            $table->dropForeign(['source_cash_account_id']);
            $table->dropForeign(['cash_transfer_document_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropUnique(['cash_transfer_document_id']);
            $table->dropIndex(['company_id', 'source_cash_account_id', 'destination_cash_account_id']);
            $table->dropColumn(['source_cash_account_id', 'cash_transfer_document_id', 'transfer_posted_at', 'reversed_by', 'reversed_at', 'reversal_reason']);
        });
    }
};
