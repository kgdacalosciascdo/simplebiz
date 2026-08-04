<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('preferred_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('legal_name', 180)->nullable();
            $table->string('primary_contact_name', 120)->nullable();
            $table->string('primary_contact_email', 255)->nullable();
            $table->string('primary_contact_phone', 40)->nullable();
            $table->json('principal_address')->nullable();
            $table->unsignedSmallInteger('fiscal_year_start_month')->nullable();
            $table->string('setup_status', 32)->default('required')->index();
            $table->timestamp('setup_completed_at')->nullable();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_protected')->default(false);
            $table->string('system_key', 100)->nullable()->unique();
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->string('operation', 120)->default('legacy');
            $table->string('scope', 180)->default('global');
            $table->string('response_identity', 120)->nullable();
            $table->dropUnique('idempotency_keys_company_id_key_unique');
            $table->unique(['scope', 'operation', 'key']);
        });

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('email', 255);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_status', 32)->default('not_configured');
            $table->timestamps();
            $table->index(['company_id', 'email', 'status']);
        });

        Schema::create('membership_access_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80)->index();
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->text('reason')->nullable();
            $table->string('source_channel', 40)->default('api');
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_access_history');
        Schema::dropIfExists('user_invitations');
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique('idempotency_keys_scope_operation_key_unique');
            $table->unique(['company_id', 'key']);
            $table->dropColumn(['operation', 'scope', 'response_identity']);
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_system_key_unique');
            $table->dropColumn(['is_protected', 'system_key']);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'primary_contact_name', 'primary_contact_email', 'primary_contact_phone', 'principal_address', 'fiscal_year_start_month', 'setup_status', 'setup_completed_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['preferred_company_id']);
            $table->dropColumn(['status', 'preferred_company_id', 'last_login_at']);
        });
    }
};
