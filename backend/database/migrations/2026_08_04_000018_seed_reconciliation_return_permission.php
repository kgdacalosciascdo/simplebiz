<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(['key' => 'cash-accounts.reconciliations.return'], ['name' => 'Return Reconciliations', 'module' => 'cash-accounts', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Retained for audit continuity.
    }
};
