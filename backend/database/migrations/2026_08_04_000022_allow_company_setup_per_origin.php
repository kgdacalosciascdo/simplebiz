<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('setup_registration_ip', 45)->nullable()->unique();
            $table->string('setup_registration_device_id', 128)->nullable()->unique();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_system_key_unique');
            $table->unique(['company_id', 'system_key']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_company_id_system_key_unique');
            $table->unique('system_key');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique('companies_setup_registration_ip_unique');
            $table->dropUnique('companies_setup_registration_device_id_unique');
            $table->dropColumn(['setup_registration_ip', 'setup_registration_device_id']);
        });
    }
};
