<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reconciliation_number' => $this->reconciliation_number, 'cash_account_id' => $this->cash_account_id, 'currency' => $this->currency_code, 'statement_import_batch_id' => $this->statement_import_batch_id, 'period_start' => $this->period_start?->toDateString(), 'period_end' => $this->period_end?->toDateString(), 'status' => $this->status, 'version' => $this->version, 'statement_opening_balance' => (string) $this->statement_opening_balance, 'statement_closing_balance' => (string) $this->statement_closing_balance, 'statement_inflows' => (string) $this->statement_inflows, 'statement_outflows' => (string) $this->statement_outflows, 'internal_opening_balance' => (string) $this->internal_opening_balance, 'internal_closing_balance' => (string) $this->internal_closing_balance, 'internal_inflows' => (string) $this->internal_inflows, 'internal_outflows' => (string) $this->internal_outflows, 'matched_statement_amount' => (string) $this->matched_statement_amount, 'matched_movement_amount' => (string) $this->matched_movement_amount, 'outstanding_statement_amount' => (string) $this->outstanding_statement_amount, 'outstanding_movement_amount' => (string) $this->outstanding_movement_amount, 'difference_amount' => (string) $this->difference_amount, 'adjustment_amount' => (string) $this->adjustment_amount, 'population_fingerprint' => $this->population_fingerprint, 'locked_at' => $this->locked_at, 'account' => $this->whenLoaded('account', fn () => ['id' => $this->account->id, 'code' => $this->account->code, 'name' => $this->account->name, 'currency' => $this->account->currency?->code]), 'batch' => new StatementImportResource($this->whenLoaded('batch')), 'matches' => $this->whenLoaded('matches'), 'outstanding_items' => $this->whenLoaded('outstandingItems'), 'history' => $this->whenLoaded('history'), 'completions' => $this->whenLoaded('completions')];
    }
}
