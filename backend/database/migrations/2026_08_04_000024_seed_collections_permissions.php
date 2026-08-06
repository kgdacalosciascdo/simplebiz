<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'collections.view' => 'View Collections & Receipts',
            'collections.receipts.create' => 'Create Receipts',
            'collections.receipts.update' => 'Edit Receipt Drafts',
            'collections.receipts.submit' => 'Submit Receipts',
            'collections.receipts.review' => 'Review Receipts',
            'collections.receipts.approve' => 'Approve Receipts',
            'collections.receipts.post' => 'Post Receipts',
            'collections.receipts.cancel' => 'Cancel Receipts',
            'collections.receipts.reverse' => 'Reverse Receipts',
            'collections.applications.create' => 'Apply Customer Payments',
            'collections.applications.reverse' => 'Reverse Payment Applications',
            'collections.unapplied.view' => 'View Unapplied Customer Receipts',
            'collections.ledger.view' => 'View Customer Ledger',
            'collections.history.view' => 'View Collection History',
        ];

        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'collections', 'created_at' => now(), 'updated_at' => now()]);
        }

        $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $memberIds = DB::table('permissions')->whereIn('key', ['collections.view', 'collections.unapplied.view', 'collections.ledger.view', 'collections.history.view'])->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach ($memberIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $keys = ['collections.view', 'collections.receipts.create', 'collections.receipts.update', 'collections.receipts.submit', 'collections.receipts.review', 'collections.receipts.approve', 'collections.receipts.post', 'collections.receipts.cancel', 'collections.receipts.reverse', 'collections.applications.create', 'collections.applications.reverse', 'collections.unapplied.view', 'collections.ledger.view', 'collections.history.view'];
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
