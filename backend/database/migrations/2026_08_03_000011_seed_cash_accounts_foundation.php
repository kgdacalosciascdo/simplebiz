<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'cash-accounts.view' => 'View Cash Accounts Workspace',
            'cash-accounts.search' => 'Search Cash Accounts',
            'cash-accounts.history' => 'View Cash Account History',
            'cash-accounts.balance.view' => 'View Cash Account Balances',
            'cash-accounts.accounts.view' => 'View Cash Account Profiles',
            'cash-accounts.accounts.create' => 'Create Cash Account Profiles',
            'cash-accounts.accounts.update' => 'Update Cash Account Profiles',
            'cash-accounts.accounts.activate' => 'Activate Cash Accounts',
            'cash-accounts.accounts.restrict' => 'Restrict Cash Accounts',
            'cash-accounts.accounts.deactivate' => 'Deactivate Cash Accounts',
            'cash-accounts.accounts.reactivate' => 'Reactivate Cash Accounts',
            'cash-accounts.capabilities.manage' => 'Manage Cash Account Capabilities',
            'cash-accounts.custodians.view' => 'View Cash Account Custodians',
            'cash-accounts.custodians.manage' => 'Manage Cash Account Custodians',
            'cash-accounts.opening-balances.view' => 'View Opening Balances',
            'cash-accounts.opening-balances.create' => 'Create Opening Balance Drafts',
            'cash-accounts.opening-balances.update' => 'Update Opening Balance Drafts',
            'cash-accounts.opening-balances.submit' => 'Submit Opening Balances',
            'cash-accounts.opening-balances.review' => 'Review Opening Balances',
            'cash-accounts.opening-balances.approve' => 'Approve Opening Balances',
            'cash-accounts.opening-balances.post' => 'Post Opening Balances',
            'cash-accounts.opening-balances.reverse' => 'Reverse Opening Balances',
            'cash-accounts.evidence.upload' => 'Upload Cash Account Evidence',
            'cash-accounts.evidence.view' => 'View Cash Account Evidence',
            'cash-accounts.evidence.download' => 'Download Cash Account Evidence',
        ];

        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'name' => $name,
                'module' => 'cash-accounts',
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        $allPermissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            foreach ($allPermissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $memberKeys = [
            'cash-accounts.view',
            'cash-accounts.search',
            'cash-accounts.history',
            'cash-accounts.balance.view',
            'cash-accounts.accounts.view',
            'cash-accounts.custodians.view',
            'cash-accounts.opening-balances.view',
            'cash-accounts.evidence.view',
        ];
        $memberPermissionIds = DB::table('permissions')->whereIn('key', $memberKeys)->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach ($memberPermissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $types = [
            ['code' => 'CASH_BOX', 'name' => 'Cash Box', 'description' => 'A physical business cash box.', 'classification' => 'physical', 'defaults' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'CASH_COUNT'], 'allowed' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'CASH_COUNT', 'ALLOW_NEGATIVE_BALANCE'], 'custodian' => true, 'count' => true],
            ['code' => 'CASH_DRAWER', 'name' => 'Cash Drawer', 'description' => 'A physical point-of-sale or service cash drawer.', 'classification' => 'physical', 'defaults' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'CASH_COUNT'], 'allowed' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'CASH_COUNT'], 'custodian' => true, 'count' => true],
            ['code' => 'PETTY_CASH', 'name' => 'Petty Cash', 'description' => 'A controlled physical petty-cash fund.', 'classification' => 'physical', 'defaults' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'CASH_COUNT'], 'allowed' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'CASH_COUNT'], 'custodian' => true, 'count' => true],
            ['code' => 'VAULT', 'name' => 'Vault', 'description' => 'A secured physical cash store.', 'classification' => 'physical', 'defaults' => ['TRANSFER_IN', 'TRANSFER_OUT', 'CASH_COUNT'], 'allowed' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'CASH_COUNT'], 'custodian' => true, 'count' => true],
            ['code' => 'DIGITAL_WALLET', 'name' => 'Digital Wallet', 'description' => 'A non-physical wallet or stored-value provider account.', 'classification' => 'non_physical', 'defaults' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT'], 'allowed' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'DEPOSIT', 'WITHDRAW', 'RECONCILE', 'ALLOW_NEGATIVE_BALANCE'], 'custodian' => false, 'count' => false],
            ['code' => 'BANK_ACCOUNT', 'name' => 'Bank Account', 'description' => 'A non-physical bank cash account.', 'classification' => 'non_physical', 'defaults' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'DEPOSIT', 'WITHDRAW', 'RECONCILE'], 'allowed' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'DEPOSIT', 'WITHDRAW', 'ISSUE_CHECK', 'STATEMENT_IMPORT', 'RECONCILE', 'ALLOW_NEGATIVE_BALANCE'], 'custodian' => false, 'count' => false],
        ];
        foreach ($types as $type) {
            DB::table('cash_account_types')->updateOrInsert(['code' => $type['code']], [
                'id' => DB::table('cash_account_types')->where('code', $type['code'])->value('id') ?: (string) Str::uuid(),
                'name' => $type['name'],
                'description' => $type['description'],
                'classification' => $type['classification'],
                'default_capabilities' => json_encode($type['defaults'], JSON_THROW_ON_ERROR),
                'allowed_capabilities' => json_encode($type['allowed'], JSON_THROW_ON_ERROR),
                'requires_custodian' => $type['custodian'],
                'supports_cash_count' => $type['count'],
                'supports_reconciliation' => in_array('RECONCILE', $type['allowed'], true),
                'supports_statement_import' => in_array('STATEMENT_IMPORT', $type['allowed'], true),
                'supports_check' => in_array('ISSUE_CHECK', $type['allowed'], true),
                'system_standard' => true,
                'locked' => true,
                'status' => 'active',
                'version' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Standard types and permission rows are retained on rollback for safety.
    }
};
