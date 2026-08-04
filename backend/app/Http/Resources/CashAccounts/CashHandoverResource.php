<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashHandoverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'handover_number' => $this->handover_number, 'cash_account_id' => $this->cash_account_id, 'cash_account' => $this->whenLoaded('account', fn () => ['id' => $this->account->id, 'code' => $this->account->code, 'name' => $this->account->name]), 'cash_count_id' => $this->cash_count_id, 'accepted_attempt_id' => $this->accepted_attempt_id, 'outgoing_custodian_id' => $this->outgoing_custodian_id, 'incoming_custodian_id' => $this->incoming_custodian_id, 'witness_id' => $this->witness_id, 'status' => $this->status, 'handover_date' => $this->handover_date?->toDateString(), 'reason' => $this->reason, 'outgoing_confirmed_by' => $this->outgoing_confirmed_by, 'incoming_confirmed_by' => $this->incoming_confirmed_by, 'approved_by' => $this->approved_by, 'completed_by' => $this->completed_by, 'completed_at' => $this->completed_at, 'version' => $this->version, 'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($item) => ['id' => $item->id, 'original_filename' => $item->original_filename])->values())];
    }
}
