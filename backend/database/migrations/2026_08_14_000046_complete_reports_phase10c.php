<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_requests', function (Blueprint $table) {
            $table->string('execution_mode', 16)->default('sync')->after('status');
            $table->dateTime('queued_at')->nullable()->after('validated_at');
            $table->unsignedInteger('queue_attempts')->default(0)->after('queued_at');
            $table->dateTime('last_attempt_at')->nullable()->after('queue_attempts');
            $table->dateTime('cancelled_at')->nullable()->after('failed_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()->after('cancelled_at');
            $table->string('failure_category', 80)->nullable()->after('failure_code');
            $table->boolean('retryable')->default(false)->after('failure_message');
            $table->json('failure_history')->nullable()->after('retryable');
            $table->index(['company_id', 'execution_mode', 'status', 'queued_at']);
        });

        foreach ([
            ['reports.requests.cancel', 'Cancel queued report requests'],
            ['reports.requests.retry', 'Retry failed report requests'],
        ] as [$key, $name]) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['name' => $name, 'module' => 'reports', 'description' => 'MDS-900 queued report request control.', 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('key', ['reports.requests.cancel', 'reports.requests.retry'])->pluck('id');
        $roleIds = DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permission_role')->whereIn('permission_id', function ($query) {
            $query->select('id')->from('permissions')->whereIn('key', ['reports.requests.cancel', 'reports.requests.retry']);
        })->delete();
        DB::table('permissions')->whereIn('key', ['reports.requests.cancel', 'reports.requests.retry'])->delete();

        Schema::table('report_requests', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropIndex(['company_id', 'execution_mode', 'status', 'queued_at']);
            $table->dropColumn(['execution_mode', 'queued_at', 'queue_attempts', 'last_attempt_at', 'cancelled_at', 'cancelled_by', 'failure_category', 'retryable', 'failure_history']);
        });
    }
};
