<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'cash-accounts.denominations.view' => 'View Cash Denominations',
            'cash-accounts.denominations.manage' => 'Manage Cash Denominations',
            'cash-accounts.cash-counts.view' => 'View Cash Counts',
            'cash-accounts.cash-counts.create' => 'Create Cash Counts',
            'cash-accounts.cash-counts.update' => 'Update Cash Counts',
            'cash-accounts.cash-counts.start' => 'Start Cash Counts',
            'cash-accounts.cash-counts.attempts' => 'Enter Cash Count Attempts',
            'cash-accounts.cash-counts.submit' => 'Submit Cash Counts',
            'cash-accounts.cash-counts.review' => 'Review Cash Counts',
            'cash-accounts.cash-counts.approve' => 'Approve Cash Counts',
            'cash-accounts.cash-counts.recount' => 'Request Cash Recounts',
            'cash-accounts.cash-counts.cancel' => 'Cancel Cash Counts',
            'cash-accounts.cash-counts.reopen' => 'Reopen Cash Counts',
            'cash-accounts.cash-counts.evidence.view' => 'View Cash Count Evidence',
            'cash-accounts.cash-counts.evidence.upload' => 'Upload Cash Count Evidence',
            'cash-accounts.variances.view' => 'View Cash Variances',
            'cash-accounts.variances.disposition' => 'Disposition Cash Variances',
            'cash-accounts.variances.approve' => 'Approve Cash Variances',
            'cash-accounts.adjustments.view' => 'View Cash Adjustments',
            'cash-accounts.adjustments.create' => 'Prepare Cash Adjustments',
            'cash-accounts.adjustments.approve' => 'Approve Cash Adjustments',
            'cash-accounts.adjustments.post' => 'Post Cash Adjustments',
            'cash-accounts.adjustments.reverse' => 'Reverse Cash Adjustments',
            'cash-accounts.handovers.view' => 'View Custodian Handovers',
            'cash-accounts.handovers.create' => 'Create Custodian Handovers',
            'cash-accounts.handovers.confirm' => 'Confirm Custodian Handovers',
            'cash-accounts.handovers.approve' => 'Approve Custodian Handovers',
            'cash-accounts.handovers.complete' => 'Complete Custodian Handovers',
            'cash-accounts.handovers.cancel' => 'Cancel Custodian Handovers',
            'cash-accounts.cash-counts.reports.view' => 'View Cash Count Reports',
        ];
        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'cash-accounts', 'updated_at' => now(), 'created_at' => now()]);
        }

        $all = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'business_owner')->pluck('id') as $roleId) {
            foreach ($all as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        $administratorKeys = ['cash-accounts.denominations.view', 'cash-accounts.cash-counts.view', 'cash-accounts.cash-counts.create', 'cash-accounts.cash-counts.update', 'cash-accounts.cash-counts.start', 'cash-accounts.cash-counts.attempts', 'cash-accounts.cash-counts.submit', 'cash-accounts.cash-counts.evidence.view', 'cash-accounts.cash-counts.evidence.upload', 'cash-accounts.variances.view', 'cash-accounts.adjustments.view', 'cash-accounts.handovers.view', 'cash-accounts.cash-counts.reports.view'];
        $administrator = DB::table('permissions')->whereIn('key', $administratorKeys)->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'administrator')->pluck('id') as $roleId) {
            foreach ($administrator as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        $memberKeys = ['cash-accounts.cash-counts.view', 'cash-accounts.cash-counts.evidence.view', 'cash-accounts.variances.view', 'cash-accounts.handovers.view', 'cash-accounts.cash-counts.reports.view'];
        $member = DB::table('permissions')->whereIn('key', $memberKeys)->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach ($member as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $types = [
            ['code' => 'ROUTINE', 'name' => 'Routine Count', 'rules' => ['witness_required' => false, 'custodian_confirmation_required' => true, 'denominations_required' => true, 'approval_required' => true, 'evidence_required' => true, 'zero_variance_auto_close' => false, 'material_variance_requires_recount' => false]],
            ['code' => 'END_OF_DAY', 'name' => 'End of Day Count', 'rules' => ['witness_required' => true, 'custodian_confirmation_required' => true, 'denominations_required' => true, 'approval_required' => true, 'evidence_required' => true, 'zero_variance_auto_close' => false, 'material_variance_requires_recount' => false]],
            ['code' => 'SPOT_CHECK', 'name' => 'Spot Check', 'rules' => ['witness_required' => false, 'custodian_confirmation_required' => true, 'denominations_required' => true, 'approval_required' => true, 'evidence_required' => true, 'zero_variance_auto_close' => false, 'material_variance_requires_recount' => false]],
            ['code' => 'CUSTODIAN_HANDOVER', 'name' => 'Custodian Handover Count', 'rules' => ['witness_required' => true, 'custodian_confirmation_required' => true, 'denominations_required' => true, 'approval_required' => true, 'evidence_required' => true, 'zero_variance_auto_close' => false, 'material_variance_requires_recount' => true]],
            ['code' => 'INVESTIGATION', 'name' => 'Investigation Count', 'rules' => ['witness_required' => true, 'custodian_confirmation_required' => true, 'denominations_required' => true, 'approval_required' => true, 'evidence_required' => true, 'zero_variance_auto_close' => false, 'material_variance_requires_recount' => true]],
            ['code' => 'CLOSURE_PREPARATION', 'name' => 'Closure Preparation Count', 'rules' => ['witness_required' => true, 'custodian_confirmation_required' => true, 'denominations_required' => true, 'approval_required' => true, 'evidence_required' => true, 'zero_variance_auto_close' => false, 'material_variance_requires_recount' => true]],
            ['code' => 'OTHER', 'name' => 'Other Controlled Count', 'rules' => ['witness_required' => false, 'custodian_confirmation_required' => true, 'denominations_required' => false, 'approval_required' => true, 'evidence_required' => true, 'zero_variance_auto_close' => false, 'material_variance_requires_recount' => false]],
        ];
        foreach ($types as $type) {
            DB::table('cash_count_types')->updateOrInsert(['code' => $type['code']], ['id' => DB::table('cash_count_types')->where('code', $type['code'])->value('id') ?: (string) Str::uuid(), 'name' => $type['name'], 'rules' => json_encode($type['rules'], JSON_THROW_ON_ERROR), 'status' => 'active', 'system_standard' => true, 'version' => 1, 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        // Catalog permissions and standard count types are retained for rollback safety.
    }
};
