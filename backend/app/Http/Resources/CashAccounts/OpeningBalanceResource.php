<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpeningBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'cash_account_id' => $this->cash_account_id, 'cash_account' => $this->whenLoaded('account', fn () => $this->account ? ['id' => $this->account->id, 'code' => $this->account->code, 'name' => $this->account->name] : null), 'currency' => $this->whenLoaded('currency', fn () => $this->currency ? ['id' => $this->currency->id, 'code' => $this->currency->code, 'name' => $this->currency->name] : null), 'effective_date' => $this->effective_date?->toDateString(), 'direction' => $this->direction, 'amount' => (string) $this->amount, 'opening_source' => $this->opening_source, 'migration_reference' => $this->migration_reference, 'offset_account_title_id' => $this->offset_account_title_id, 'reason_code' => $this->whenLoaded('reasonCode', fn () => $this->reasonCode ? ['id' => $this->reasonCode->id, 'code' => $this->reasonCode->code, 'name' => $this->reasonCode->name, 'domain' => $this->reasonCode->domain] : null), 'explanation' => $this->explanation, 'batch_reference' => $this->batch_reference, 'status' => $this->status, 'version' => $this->version, 'prepared_by' => $this->prepared_by, 'prepared_at' => $this->prepared_at, 'submitted_by' => $this->submitted_by, 'submitted_at' => $this->submitted_at, 'approved_by' => $this->approved_by, 'approved_at' => $this->approved_at, 'returned_by' => $this->returned_by, 'returned_at' => $this->returned_at, 'return_reason' => $this->return_reason, 'posted_by' => $this->posted_by, 'posted_at' => $this->posted_at, 'reversed_by' => $this->reversed_by, 'reversed_at' => $this->reversed_at, 'reversal_reason' => $this->reversal_reason, 'business_transaction_id' => $this->business_transaction_id, 'cash_movement_id' => $this->cash_movement_id, 'correlation_id' => $this->correlation_id, 'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => ['id' => $attachment->id, 'original_filename' => $attachment->original_filename, 'mime_type' => $attachment->mime_type, 'file_size' => $attachment->file_size, 'file_hash' => $attachment->file_hash, 'uploaded_at' => $attachment->created_at])->values()), 'movement' => $this->whenLoaded('movement', fn () => $this->movement ? new CashMovementResource($this->movement) : null)];
    }
}
