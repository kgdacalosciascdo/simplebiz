<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'inventory.view' => 'View Inventory Workspace',
            'inventory.balances.view' => 'View Inventory Balances',
            'inventory.movements.view' => 'View Stock Movements',
            'inventory.receipts.create' => 'Create Stock Receipts',
            'inventory.receipts.post' => 'Post Stock Receipts',
            'inventory.receipts.reverse' => 'Reverse Stock Receipts',
            'inventory.issues.create' => 'Create Stock Issues',
            'inventory.issues.post' => 'Post Stock Issues',
            'inventory.issues.reverse' => 'Reverse Stock Issues',
            'inventory.opening-stock.create' => 'Create Opening Stock',
            'inventory.opening-stock.post' => 'Post Opening Stock',
            'inventory.opening-stock.reverse' => 'Reverse Opening Stock',
            'inventory.transfers.view' => 'View Stock Transfers',
            'inventory.transfers.create' => 'Create Stock Transfers',
            'inventory.transfers.post' => 'Post Stock Transfers',
            'inventory.transfers.reverse' => 'Reverse Stock Transfers',
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
        $member = ['inventory.view', 'inventory.balances.view', 'inventory.movements.view', 'inventory.transfers.view'];
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach (DB::table('permissions')->whereIn('key', $member)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('module', 'inventory')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
