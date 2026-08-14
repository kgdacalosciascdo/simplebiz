<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_instruments', function (Blueprint $table) {
            $table->dropUnique('payment_instruments_company_id_payment_instruction_id_instrument_type_unique');
            $table->index(['company_id', 'payment_instruction_id', 'instrument_type']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_instruments', function (Blueprint $table) {
            $table->dropIndex('payment_instruments_company_id_payment_instruction_id_instrument_type_index');
            $table->unique(['company_id', 'payment_instruction_id', 'instrument_type']);
        });
    }
};
