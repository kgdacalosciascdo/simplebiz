<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CashDocumentNumberService
{
    public function next(int $companyId, string $sequenceKey): string
    {
        $prefixes = ['cash_in' => 'CIN', 'cash_out' => 'COUT', 'transfer' => 'TRF', 'cash_in_reversal' => 'CIN-R', 'cash_out_reversal' => 'COUT-R', 'transfer_reversal' => 'TRF-R', 'cash_count' => 'CNT', 'cash_adjustment' => 'CADJ', 'custodian_handover' => 'HND'];
        $prefix = $prefixes[$sequenceKey] ?? 'CASH';
        DB::table('cash_document_sequences')->insertOrIgnore(['id' => (string) Str::uuid(), 'company_id' => $companyId, 'sequence_key' => $sequenceKey, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sequence = DB::table('cash_document_sequences')->where('company_id', $companyId)->where('sequence_key', $sequenceKey)->lockForUpdate()->first();
        $number = (int) $sequence->next_number;
        DB::table('cash_document_sequences')->where('id', $sequence->id)->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return sprintf('%s-%06d', $prefix, $number);
    }
}
