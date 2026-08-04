<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'adjustment_number' => $this->adjustment_number, 'cash_count_id' => $this->cash_count_id, 'variance_id' => $this->variance_id, 'status' => $this->status, 'direction' => $this->direction, 'amount' => (string) $this->amount, 'offset_account_title_id' => $this->offset_account_title_id, 'reason_code_id' => $this->reason_code_id, 'cash_movement_document_id' => $this->cash_movement_document_id, 'cash_movement_id' => $this->cash_movement_id, 'accounting_transaction_id' => $this->accounting_transaction_id, 'original_adjustment_id' => $this->original_adjustment_id, 'reversal_adjustment_id' => $this->reversal_adjustment_id, 'reason' => $this->reason, 'reversal_reason' => $this->reversal_reason, 'prepared_by' => $this->prepared_by, 'approved_by' => $this->approved_by, 'posted_by' => $this->posted_by, 'reversed_by' => $this->reversed_by, 'posted_at' => $this->posted_at, 'version' => $this->version, 'movement_document' => $this->whenLoaded('movementDocument', fn () => $this->movementDocument ? new CashMovementDocumentResource($this->movementDocument) : null)];
    }
}
