<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registry_external_identifiers', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status_reason')->nullable();
            $table->index(['company_id', 'registry_type', 'record_id', 'status'], 'registry_identifier_lookup_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE registry_external_identifiers ADD CONSTRAINT registry_external_identifiers_effective_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE registry_external_identifiers DROP CONSTRAINT IF EXISTS registry_external_identifiers_effective_dates');
        }

        Schema::table('registry_external_identifiers', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropForeign(['status_changed_by']);
            $table->dropIndex('registry_identifier_lookup_idx');
            $table->dropColumn(['version', 'effective_from', 'effective_to', 'created_by', 'updated_by', 'status_changed_at', 'status_changed_by', 'status_reason']);
        });

    }
};
