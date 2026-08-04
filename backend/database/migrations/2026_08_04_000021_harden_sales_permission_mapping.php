<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales.history' => 'View Sales History', 'sales.credit-override' => 'Override Credit Controls'] as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'sales', 'created_at' => now(), 'updated_at' => now()]);
        }

        $administratorIds = DB::table('roles')->where('system_key', 'administrator')->pluck('id');
        $restrictedKeys = ['sales.approve', 'sales.post', 'sales.cancel', 'sales.price-override', 'sales.discount-override', 'sales.credit-override'];
        $restrictedIds = DB::table('permissions')->whereIn('key', $restrictedKeys)->pluck('id');
        DB::table('permission_role')->whereIn('role_id', $administratorIds)->whereIn('permission_id', $restrictedIds)->delete();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', ['sales.history', 'sales.credit-override'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
