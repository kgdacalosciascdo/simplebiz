<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'cash-accounts.movements.view' => 'View Cash Movement Documents',
            'cash-accounts.movements.history' => 'View Cash Movement History',
            'cash-accounts.movements.evidence.view' => 'View Cash Movement Evidence',
            'cash-accounts.movements.evidence.download' => 'Download Cash Movement Evidence',
            'cash-accounts.movements.evidence.upload' => 'Upload Cash Movement Evidence',
            'cash-accounts.cash-in.create' => 'Create Direct Cash In',
            'cash-accounts.cash-in.update' => 'Update Direct Cash In',
            'cash-accounts.cash-in.submit' => 'Submit Direct Cash In',
            'cash-accounts.cash-in.review' => 'Review Direct Cash In',
            'cash-accounts.cash-in.approve' => 'Approve Direct Cash In',
            'cash-accounts.cash-in.post' => 'Post Direct Cash In',
            'cash-accounts.cash-in.cancel' => 'Cancel Direct Cash In',
            'cash-accounts.cash-in.reverse' => 'Reverse Direct Cash In',
            'cash-accounts.cash-out.create' => 'Create Direct Cash Out',
            'cash-accounts.cash-out.update' => 'Update Direct Cash Out',
            'cash-accounts.cash-out.submit' => 'Submit Direct Cash Out',
            'cash-accounts.cash-out.review' => 'Review Direct Cash Out',
            'cash-accounts.cash-out.approve' => 'Approve Direct Cash Out',
            'cash-accounts.cash-out.post' => 'Post Direct Cash Out',
            'cash-accounts.cash-out.cancel' => 'Cancel Direct Cash Out',
            'cash-accounts.cash-out.reverse' => 'Reverse Direct Cash Out',
            'cash-accounts.transfers.view' => 'View Cash Transfers',
            'cash-accounts.transfers.create' => 'Create Cash Transfers',
            'cash-accounts.transfers.update' => 'Update Cash Transfers',
            'cash-accounts.transfers.submit' => 'Submit Cash Transfers',
            'cash-accounts.transfers.review' => 'Review Cash Transfers',
            'cash-accounts.transfers.approve' => 'Approve Cash Transfers',
            'cash-accounts.transfers.post' => 'Post Cash Transfers',
            'cash-accounts.transfers.cancel' => 'Cancel Cash Transfers',
            'cash-accounts.transfers.reverse' => 'Reverse Cash Transfers',
            'cash-accounts.negative-balance.override' => 'Override Negative Cash Balance',
        ];

        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'cash-accounts', 'updated_at' => now(), 'created_at' => now()]);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'business_owner')->pluck('id') as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $inquiryKeys = ['cash-accounts.movements.view', 'cash-accounts.movements.history', 'cash-accounts.movements.evidence.view', 'cash-accounts.transfers.view'];
        $administratorKeys = [...$inquiryKeys, 'cash-accounts.movements.evidence.download', 'cash-accounts.movements.evidence.upload', 'cash-accounts.cash-in.create', 'cash-accounts.cash-in.update', 'cash-accounts.cash-in.submit', 'cash-accounts.cash-out.create', 'cash-accounts.cash-out.update', 'cash-accounts.cash-out.submit', 'cash-accounts.transfers.create', 'cash-accounts.transfers.update', 'cash-accounts.transfers.submit'];
        foreach (DB::table('roles')->where('system_key', 'administrator')->pluck('id') as $roleId) {
            foreach (DB::table('permissions')->whereIn('key', $administratorKeys)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach (DB::table('permissions')->whereIn('key', $inquiryKeys)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $purposes = [
            ['code' => 'DIRECT_CASH_IN', 'name' => 'Direct Cash In', 'document_kind' => 'cash_in', 'direction' => 'increase', 'required_capability' => 'RECEIVE_FUNDS', 'requires_destination' => false, 'requires_payment_method' => false, 'requires_evidence' => true, 'reason_domain' => 'CASH_MOVEMENT', 'clearing_mode' => 'not_applicable', 'allowed_source_types' => ['OWNER_CONTRIBUTION', 'LOAN_PROCEEDS', 'INTEREST_INCOME', 'MISCELLANEOUS_INCOME', 'NON_CUSTOMER_REFUND', 'CASH_ADJUSTMENT']],
            ['code' => 'DIRECT_CASH_OUT', 'name' => 'Direct Cash Out', 'document_kind' => 'cash_out', 'direction' => 'decrease', 'required_capability' => 'MAKE_PAYMENTS', 'requires_destination' => false, 'requires_payment_method' => false, 'requires_evidence' => true, 'reason_domain' => 'CASH_MOVEMENT', 'clearing_mode' => 'not_applicable', 'allowed_source_types' => ['OWNER_WITHDRAWAL', 'LOAN_REPAYMENT', 'BANK_CHARGE', 'MISCELLANEOUS_OUTFLOW', 'CASH_ADJUSTMENT']],
            ['code' => 'INTERNAL_TRANSFER', 'name' => 'Internal Transfer', 'document_kind' => 'transfer', 'direction' => null, 'required_capability' => 'TRANSFER_OUT', 'requires_destination' => true, 'requires_payment_method' => false, 'requires_evidence' => true, 'reason_domain' => 'TRANSFER', 'clearing_mode' => 'not_applicable', 'allowed_source_types' => ['INTERNAL_TRANSFER']],
            ['code' => 'DEPOSIT', 'name' => 'Deposit', 'document_kind' => 'transfer', 'direction' => null, 'required_capability' => 'TRANSFER_OUT', 'requires_destination' => true, 'requires_payment_method' => false, 'requires_evidence' => true, 'reason_domain' => 'DEPOSIT', 'clearing_mode' => 'not_applicable', 'allowed_source_types' => ['DEPOSIT']],
            ['code' => 'WITHDRAWAL', 'name' => 'Withdrawal', 'document_kind' => 'transfer', 'direction' => null, 'required_capability' => 'TRANSFER_OUT', 'requires_destination' => true, 'requires_payment_method' => false, 'requires_evidence' => true, 'reason_domain' => 'WITHDRAWAL', 'clearing_mode' => 'not_applicable', 'allowed_source_types' => ['WITHDRAWAL']],
            ['code' => 'DIRECT_CASH_IN_REVERSAL', 'name' => 'Direct Cash In Reversal', 'document_kind' => 'cash_in', 'direction' => 'decrease', 'required_capability' => 'RECEIVE_FUNDS', 'requires_destination' => false, 'requires_payment_method' => false, 'requires_evidence' => false, 'reason_domain' => 'CASH_MOVEMENT', 'clearing_mode' => 'not_applicable', 'allowed_source_types' => ['DIRECT_MOVEMENT_REVERSAL']],
            ['code' => 'DIRECT_CASH_OUT_REVERSAL', 'name' => 'Direct Cash Out Reversal', 'document_kind' => 'cash_out', 'direction' => 'increase', 'required_capability' => 'MAKE_PAYMENTS', 'requires_destination' => false, 'requires_payment_method' => false, 'requires_evidence' => false, 'reason_domain' => 'CASH_MOVEMENT', 'clearing_mode' => 'not_applicable', 'allowed_source_types' => ['DIRECT_MOVEMENT_REVERSAL']],
        ];
        foreach ($purposes as $purpose) {
            DB::table('cash_movement_purposes')->updateOrInsert(['code' => $purpose['code']], [
                'id' => DB::table('cash_movement_purposes')->where('code', $purpose['code'])->value('id') ?: (string) Str::uuid(),
                'name' => $purpose['name'], 'document_kind' => $purpose['document_kind'], 'direction' => $purpose['direction'], 'required_capability' => $purpose['required_capability'], 'requires_destination' => $purpose['requires_destination'], 'requires_payment_method' => $purpose['requires_payment_method'], 'requires_evidence' => $purpose['requires_evidence'], 'reason_domain' => $purpose['reason_domain'], 'clearing_mode' => $purpose['clearing_mode'], 'approval_required' => true, 'reversal_allowed' => true, 'owning_module' => 'cash-accounts', 'allowed_source_types' => json_encode($purpose['allowed_source_types'], JSON_THROW_ON_ERROR), 'status' => 'active', 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Catalog rows and permissions are retained on rollback for safety.
    }
};
