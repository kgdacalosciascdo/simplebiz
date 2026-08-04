<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'cash-accounts.statements.view' => 'View Statement Imports',
            'cash-accounts.statements.import' => 'Import Statement Evidence',
            'cash-accounts.statements.validate' => 'Validate Statement Imports',
            'cash-accounts.statements.cancel' => 'Cancel Statement Imports',
            'cash-accounts.statements.evidence.view' => 'View Statement Evidence',
            'cash-accounts.statements.evidence.download' => 'Download Statement Evidence',
            'cash-accounts.reconciliation.matches.view' => 'View Reconciliation Matches',
            'cash-accounts.reconciliation.matches.create' => 'Create Reconciliation Matches',
            'cash-accounts.reconciliation.matches.confirm' => 'Confirm Reconciliation Matches',
            'cash-accounts.reconciliation.matches.unmatch' => 'Unmatch Reconciliation Matches',
            'cash-accounts.reconciliation.matches.override-tolerance' => 'Override Reconciliation Tolerance',
            'cash-accounts.reconciliations.view' => 'View Reconciliations',
            'cash-accounts.reconciliations.create' => 'Create Reconciliations',
            'cash-accounts.reconciliations.update' => 'Update Reconciliations',
            'cash-accounts.reconciliations.prepare' => 'Prepare Reconciliations',
            'cash-accounts.reconciliations.submit' => 'Submit Reconciliations',
            'cash-accounts.reconciliations.review' => 'Review Reconciliations',
            'cash-accounts.reconciliations.approve' => 'Approve Reconciliations',
            'cash-accounts.reconciliations.complete' => 'Complete Reconciliations',
            'cash-accounts.reconciliations.reopen' => 'Reopen Reconciliations',
            'cash-accounts.reconciliations.cancel' => 'Cancel Reconciliations',
            'cash-accounts.reconciliation-adjustments.create' => 'Prepare Reconciliation Adjustments',
            'cash-accounts.reconciliation-adjustments.review' => 'Review Reconciliation Adjustments',
            'cash-accounts.reconciliation-adjustments.approve' => 'Approve Reconciliation Adjustments',
            'cash-accounts.reconciliation-adjustments.post' => 'Post Reconciliation Adjustments',
            'cash-accounts.reconciliation-adjustments.reverse' => 'Reverse Reconciliation Adjustments',
        ];
        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'name' => $name, 'module' => 'cash-accounts', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $all = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'business_owner')->pluck('id') as $roleId) {
            foreach ($all as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $administratorKeys = [
            'cash-accounts.statements.view', 'cash-accounts.statements.import', 'cash-accounts.statements.validate', 'cash-accounts.statements.evidence.view',
            'cash-accounts.reconciliation.matches.view', 'cash-accounts.reconciliation.matches.create', 'cash-accounts.reconciliation.matches.confirm', 'cash-accounts.reconciliation.matches.unmatch',
            'cash-accounts.reconciliations.view', 'cash-accounts.reconciliations.create', 'cash-accounts.reconciliations.update', 'cash-accounts.reconciliations.prepare', 'cash-accounts.reconciliations.submit', 'cash-accounts.reconciliations.review',
        ];
        $administrator = DB::table('permissions')->whereIn('key', $administratorKeys)->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'administrator')->pluck('id') as $roleId) {
            foreach ($administrator as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $memberKeys = ['cash-accounts.statements.view', 'cash-accounts.statements.evidence.view', 'cash-accounts.reconciliation.matches.view', 'cash-accounts.reconciliations.view'];
        $member = DB::table('permissions')->whereIn('key', $memberKeys)->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach ($member as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        // Permission rows remain on rollback for safety and audit continuity.
    }
};
