<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'sales.view' => 'View Sales',
            'sales.create' => 'Create Sales',
            'sales.update' => 'Update Sales',
            'sales.submit' => 'Submit Sales',
            'sales.review' => 'Review Sales',
            'sales.approve' => 'Approve Sales',
            'sales.post' => 'Post Sales',
            'sales.cancel' => 'Cancel Sales',
            'sales.price-override' => 'Override Sales Prices',
            'sales.discount-override' => 'Override Sales Discounts',
            'sales.receivables.view' => 'View Receivables',
            'sales.receivables.aging.view' => 'View Receivables Aging',
            'sales.billing-statements.view' => 'View Billing Statements',
            'sales.billing-statements.create' => 'Create Billing Statements',
        ];

        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'sales', 'created_at' => now(), 'updated_at' => now()]);
        }

        $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $member = ['sales.view', 'sales.receivables.view', 'sales.receivables.aging.view', 'sales.billing-statements.view'];
        $memberIds = DB::table('permissions')->whereIn('key', $member)->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach ($memberIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $keys = ['sales.view', 'sales.create', 'sales.update', 'sales.submit', 'sales.review', 'sales.approve', 'sales.post', 'sales.cancel', 'sales.price-override', 'sales.discount-override', 'sales.receivables.view', 'sales.receivables.aging.view', 'sales.billing-statements.view', 'sales.billing-statements.create'];
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
