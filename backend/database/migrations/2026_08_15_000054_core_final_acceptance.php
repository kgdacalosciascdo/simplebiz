<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('notification_key', 180);
            $table->string('event_key', 120);
            $table->string('source_module', 60);
            $table->string('title', 180);
            $table->text('body');
            $table->string('severity', 24)->default('info');
            $table->string('source_type', 180)->nullable();
            $table->string('source_id', 180)->nullable();
            $table->string('route', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'notification_key']);
            $table->index(['company_id', 'user_id', 'read_at']);
            $table->index(['company_id', 'event_key']);
        });

        $permission = Permission::firstOrCreate(
            ['key' => 'core.search'],
            ['name' => 'Use Global Search', 'module' => 'core', 'description' => 'Search authorized records in the active company scope.']
        );

        Role::whereIn('system_key', ['business_owner', 'administrator', 'member'])
            ->where('status', 'active')
            ->get()
            ->each(function (Role $role) use ($permission): void {
                if (! $role->permissions()->whereKey($permission->id)->exists()) {
                    $role->permissions()->attach($permission->id);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Permission::where('key', 'core.search')->delete();
    }
};
