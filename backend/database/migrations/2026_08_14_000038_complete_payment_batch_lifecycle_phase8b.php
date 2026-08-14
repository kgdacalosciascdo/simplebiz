<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_batches', function (Blueprint $table) {
            $table->foreignId('generated_by')->nullable()->after('released_by')->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->after('generated_by')->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable()->after('released_at');
            $table->timestamp('closed_at')->nullable()->after('generated_at');
        });

        $permissions = [
            'payments.batches.validate' => 'Validate Payment Batches',
            'payments.batches.generate' => 'Generate Payment Batches',
            'payments.batches.close' => 'Close Payment Batches',
        ];

        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['name' => $name, 'module' => 'payments', 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', [
            'payments.batches.validate',
            'payments.batches.generate',
            'payments.batches.close',
        ])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('payment_batches', function (Blueprint $table) {
            $table->dropForeign(['generated_by']);
            $table->dropForeign(['closed_by']);
            $table->dropColumn(['generated_by', 'closed_by', 'generated_at', 'closed_at']);
        });
    }
};
