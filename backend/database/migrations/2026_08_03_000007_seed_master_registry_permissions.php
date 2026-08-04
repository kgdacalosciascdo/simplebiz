<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'master-registries.view' => ['View Master Registries', 'master-registries'],
            'master-registries.search' => ['Search Master Registries', 'master-registries'],
            'master-registries.history' => ['View Master Registry History', 'master-registries'],
            'master-registries.business-partners.view' => ['View Business Partners', 'master-registries'],
            'master-registries.business-partners.create' => ['Create Business Partners', 'master-registries'],
            'master-registries.business-partners.update' => ['Update Business Partners', 'master-registries'],
            'master-registries.business-partners.deactivate' => ['Deactivate Business Partners', 'master-registries'],
            'master-registries.business-partners.reactivate' => ['Reactivate Business Partners', 'master-registries'],
            'master-registries.items.view' => ['View Products and Services', 'master-registries'],
            'master-registries.items.create' => ['Create Products and Services', 'master-registries'],
            'master-registries.items.update' => ['Update Products and Services', 'master-registries'],
            'master-registries.items.deactivate' => ['Deactivate Products and Services', 'master-registries'],
            'master-registries.items.reactivate' => ['Reactivate Products and Services', 'master-registries'],
            'master-registries.categories.view' => ['View Product Categories', 'master-registries'],
            'master-registries.categories.create' => ['Create Product Categories', 'master-registries'],
            'master-registries.categories.update' => ['Update Product Categories', 'master-registries'],
            'master-registries.categories.deactivate' => ['Deactivate Product Categories', 'master-registries'],
            'master-registries.categories.reactivate' => ['Reactivate Product Categories', 'master-registries'],
            'master-registries.units.view' => ['View Units of Measure', 'master-registries'],
            'master-registries.units.create' => ['Create Units of Measure', 'master-registries'],
            'master-registries.units.update' => ['Update Units of Measure', 'master-registries'],
            'master-registries.units.deactivate' => ['Deactivate Units of Measure', 'master-registries'],
            'master-registries.units.reactivate' => ['Reactivate Units of Measure', 'master-registries'],
        ];

        foreach ($permissions as $key => [$name, $module]) {
            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'name' => $name,
                'module' => $module,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        $roles = DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->get(['id']);
        foreach ($roles as $role) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $role->id,
                ]);
            }
        }

        $memberKeys = ['master-registries.view', 'master-registries.search', 'master-registries.history'];
        $memberPermissionIds = DB::table('permissions')->whereIn('key', $memberKeys)->pluck('id');
        $memberRoles = DB::table('roles')->where('system_key', 'member')->get(['id']);
        foreach ($memberRoles as $role) {
            foreach ($memberPermissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $role->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'master-registries.view', 'master-registries.search', 'master-registries.history',
            'master-registries.business-partners.view', 'master-registries.business-partners.create',
            'master-registries.business-partners.update', 'master-registries.business-partners.deactivate',
            'master-registries.business-partners.reactivate', 'master-registries.items.view',
            'master-registries.items.create', 'master-registries.items.update', 'master-registries.items.deactivate',
            'master-registries.items.reactivate', 'master-registries.categories.view',
            'master-registries.categories.create', 'master-registries.categories.update',
            'master-registries.categories.deactivate', 'master-registries.categories.reactivate',
            'master-registries.units.view', 'master-registries.units.create', 'master-registries.units.update',
            'master-registries.units.deactivate', 'master-registries.units.reactivate',
        ];
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
