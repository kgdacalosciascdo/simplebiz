<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_instruction_id')->constrained('payment_instructions')->restrictOnDelete();
            $table->string('voucher_number', 80);
            $table->string('status', 24)->default('issued');
            $table->unsignedInteger('reprint_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('last_reprinted_at')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'voucher_number']);
            $table->unique(['company_id', 'payment_instruction_id']);
        });

        DB::table('permissions')->updateOrInsert(['key' => 'payments.voucher.view'], ['name' => 'View Disbursement Vouchers', 'module' => 'payments', 'created_at' => now(), 'updated_at' => now()]);
        $permissionId = DB::table('permissions')->where('key', 'payments.voucher.view')->value('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('key', 'payments.voucher.view')->value('id');
        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
        Schema::dropIfExists('payment_vouchers');
    }
};
