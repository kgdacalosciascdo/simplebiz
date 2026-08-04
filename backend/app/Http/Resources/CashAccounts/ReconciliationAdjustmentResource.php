<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReconciliationAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'reconciliation_id' => $this->reconciliation_id, 'outstanding_item_id' => $this->outstanding_item_id, 'adjustment_number' => $this->adjustment_number, 'direction' => $this->direction, 'amount' => (string) $this->amount, 'offset_account_title_id' => $this->offset_account_title_id, 'reason_code_id' => $this->reason_code_id, 'explanation' => $this->explanation, 'status' => $this->status, 'cash_movement_document_id' => $this->cash_movement_document_id, 'prepared_by' => $this->prepared_by, 'approved_by' => $this->approved_by, 'posted_by' => $this->posted_by, 'posted_at' => $this->posted_at, 'version' => $this->version, 'attachments' => AttachmentResource::collection($this->whenLoaded('attachments'))];
    }
}
