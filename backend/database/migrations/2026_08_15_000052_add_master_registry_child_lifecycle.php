<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['business_partner_contacts', 'business_partner_addresses'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('status_changed_at')->nullable();
                $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('status_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['business_partner_contacts', 'business_partner_addresses'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['status_changed_by']);
                $table->dropColumn(['status_changed_at', 'status_changed_by', 'status_reason']);
            });
        }
    }
};
