<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('branding')->nullable();
            $table->json('tax_registration_references')->nullable();
            $table->string('date_format', 40)->nullable();
            $table->string('number_format', 40)->nullable();
            $table->string('paper_size', 20)->nullable();
            $table->json('document_preferences')->nullable();
            $table->json('module_preferences')->nullable();
            $table->json('notification_defaults')->nullable();
            $table->unsignedInteger('settings_version')->default(1);
            $table->timestamp('settings_updated_at')->nullable();
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('locale', 12)->nullable();
            $table->string('timezone', 80)->nullable();
            $table->string('date_format', 40)->nullable();
            $table->string('number_format', 40)->nullable();
            $table->string('paper_size', 20)->nullable();
            $table->json('accessibility')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->json('display_preferences')->nullable();
            $table->uuid('preferred_branch_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'company_id']);
        });

        Schema::create('configuration_change_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('domain', 80);
            $table->string('setting_key', 160);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 32)->default('draft')->index();
            $table->json('scope')->nullable();
            $table->json('proposed_values')->nullable();
            $table->json('previous_values')->nullable();
            $table->json('effective_values')->nullable();
            $table->json('validation_result')->nullable();
            $table->json('approval_result')->nullable();
            $table->json('publication_result')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('source_channel', 40)->default('api');
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'domain', 'setting_key', 'version']);
        });

        Schema::create('company_ownership_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('proposed_owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->text('reason');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('admin_export_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('export_type', 80);
            $table->json('scope')->nullable();
            $table->text('purpose');
            $table->string('status', 32)->default('submitted')->index();
            $table->json('package_payload')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->index(['company_id', 'requested_by', 'status']);
        });

        $permissions = [
            'settings.workspace.view' => ['View Settings Workspace', 'settings'],
            'settings.account.view' => ['View Account & Subscription', 'settings'],
            'settings.profile.edit' => ['Edit Personal Profile', 'settings'],
            'settings.preferences.edit' => ['Edit Personal Preferences', 'settings'],
            'settings.access.view' => ['View Company Access', 'settings'],
            'settings.sessions.view' => ['View Security Sessions', 'settings'],
            'settings.sessions.terminate' => ['Terminate Security Sessions', 'settings'],
            'settings.configuration.view' => ['View Effective Configuration', 'settings'],
            'settings.configuration.manage' => ['Manage Company Configuration', 'settings'],
            'settings.notifications.view' => ['View Notification Settings', 'settings'],
            'settings.notifications.edit' => ['Edit Notification Settings', 'settings'],
            'settings.modules.view' => ['View Module Preferences', 'settings'],
            'settings.modules.edit' => ['Manage Module Preferences', 'settings'],
            'settings.numbering.view' => ['View Numbering Configuration', 'settings'],
            'settings.finance.view' => ['View Financial Configuration', 'settings'],
            'settings.exports.create' => ['Request Administrative Export', 'settings'],
            'settings.exports.view' => ['View Administrative Exports', 'settings'],
            'settings.audit.export' => ['Export Administrative Audit', 'settings'],
            'settings.owner.transfer' => ['Transfer Company Ownership', 'settings'],
        ];

        foreach ($permissions as $key => [$name, $module]) {
            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'name' => $name,
                'module' => $module,
                'description' => $name,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        $adminPermissionIds = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        $adminRoles = DB::table('roles')->where('system_key', 'administrator')->pluck('id');
        foreach ($adminRoles as $roleId) {
            foreach ($adminPermissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'settings.workspace.view', 'settings.account.view', 'settings.profile.edit', 'settings.preferences.edit',
            'settings.access.view', 'settings.sessions.view', 'settings.sessions.terminate', 'settings.configuration.view',
            'settings.configuration.manage', 'settings.notifications.view', 'settings.notifications.edit',
            'settings.modules.view', 'settings.modules.edit', 'settings.numbering.view', 'settings.finance.view',
            'settings.exports.create', 'settings.exports.view', 'settings.audit.export', 'settings.owner.transfer',
        ];
        $permissionIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        Schema::dropIfExists('admin_export_requests');
        Schema::dropIfExists('company_ownership_transfers');
        Schema::dropIfExists('configuration_change_sets');
        Schema::dropIfExists('user_preferences');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['branding', 'tax_registration_references', 'date_format', 'number_format', 'paper_size', 'document_preferences', 'module_preferences', 'notification_defaults', 'settings_version', 'settings_updated_at']);
        });
    }
};
