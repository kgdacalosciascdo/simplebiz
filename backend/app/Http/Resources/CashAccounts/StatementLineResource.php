<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StatementLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'statement_import_batch_id' => $this->statement_import_batch_id, 'cash_account_id' => $this->cash_account_id, 'source_row_number' => $this->source_row_number, 'external_line_id' => $this->external_line_id, 'transaction_date' => $this->transaction_date?->toDateString(), 'value_date' => $this->value_date?->toDateString(), 'posting_date' => $this->posting_date?->toDateString(), 'description' => $this->description, 'reference' => $this->reference, 'counterparty_name' => $this->counterparty_name, 'external_account_reference' => $this->external_account_reference, 'debit_amount' => (string) $this->debit_amount, 'credit_amount' => (string) $this->credit_amount, 'signed_amount' => (string) $this->signed_amount, 'currency' => $this->currency_code, 'running_balance' => $this->running_balance === null ? null : (string) $this->running_balance, 'provider_transaction_type' => $this->provider_transaction_type, 'normalized_transaction_type' => $this->normalized_transaction_type, 'normalization_version' => $this->normalization_version, 'fingerprint' => $this->fingerprint, 'validation_status' => $this->validation_status, 'validation_errors' => $this->validation_errors, 'match_status' => $this->match_status, 'reconciliation_status' => $this->reconciliation_status, 'version' => $this->version];
    }
}
