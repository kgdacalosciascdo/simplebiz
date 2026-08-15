<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->string('closure_status', 32)->default('none')->index();
            $table->string('closure_original_status', 32)->nullable();
            $table->text('closure_reason')->nullable();
            $table->date('closure_effective_date')->nullable();
            $table->json('closure_blockers')->nullable();
            $table->foreignId('closure_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closure_requested_at')->nullable();
            $table->foreignId('closure_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closure_reviewed_at')->nullable();
            $table->foreignId('closure_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closure_approved_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closure_cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closure_cancelled_at')->nullable();
        });

        $permissions = [
            'cash-accounts.account.closure.request' => 'Request Cash Account Closure',
            'cash-accounts.account.closure.review' => 'Review Cash Account Closure',
            'cash-accounts.account.closure.approve' => 'Approve Cash Account Closure',
            'cash-accounts.account.closure.close' => 'Close Cash Accounts',
            'cash-accounts.account.closure.cancel' => 'Cancel Cash Account Closure',
        ];

        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'cash-accounts', 'updated_at' => now(), 'created_at' => now()]);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', [
            'cash-accounts.account.closure.request',
            'cash-accounts.account.closure.review',
            'cash-accounts.account.closure.approve',
            'cash-accounts.account.closure.close',
            'cash-accounts.account.closure.cancel',
        ])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropForeign(['closure_requested_by']);
            $table->dropForeign(['closure_reviewed_by']);
            $table->dropForeign(['closure_approved_by']);
            $table->dropForeign(['closed_by']);
            $table->dropForeign(['closure_cancelled_by']);
            $table->dropColumn([
                'closure_status', 'closure_original_status', 'closure_reason', 'closure_effective_date', 'closure_blockers',
                'closure_requested_by', 'closure_requested_at', 'closure_reviewed_by', 'closure_reviewed_at',
                'closure_approved_by', 'closure_approved_at', 'closed_by', 'closed_at', 'closure_cancelled_by', 'closure_cancelled_at',
            ]);
        });
    }
};
