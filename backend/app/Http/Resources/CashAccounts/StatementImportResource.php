<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StatementImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'company_id' => $this->company_id, 'cash_account_id' => $this->cash_account_id, 'batch_number' => $this->batch_number,
            'provider_reference' => $this->provider_reference, 'statement_account_identifier_snapshot' => $this->statement_account_identifier_snapshot,
            'period_start' => $this->period_start?->toDateString(), 'period_end' => $this->period_end?->toDateString(), 'currency' => $this->currency_code,
            'status' => $this->status, 'version' => $this->version, 'file' => $this->original_filename ? ['original_filename' => $this->original_filename, 'file_hash' => $this->file_hash, 'format' => $this->file_format] : null,
            'parser_version' => $this->parser_version, 'opening_statement_balance' => (string) $this->opening_statement_balance, 'closing_statement_balance' => (string) $this->closing_statement_balance,
            'total_debit' => (string) $this->total_debit, 'total_credit' => (string) $this->total_credit, 'line_count' => $this->line_count, 'parsed_line_count' => $this->parsed_line_count, 'rejected_line_count' => $this->rejected_line_count, 'duplicate_line_count' => $this->duplicate_line_count,
            'validation_summary' => $this->validation_summary, 'account' => $this->whenLoaded('account', fn () => ['id' => $this->account->id, 'code' => $this->account->code, 'name' => $this->account->name, 'currency' => $this->account->currency?->code]),
            'lines' => StatementLineResource::collection($this->whenLoaded('lines')), 'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
