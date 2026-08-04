<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashCountAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'cash_count_id' => $this->cash_count_id, 'attempt_number' => $this->attempt_number, 'status' => $this->status, 'actual_amount' => (string) $this->actual_amount, 'counted_by' => $this->counted_by, 'witnessed_by' => $this->witnessed_by, 'started_at' => $this->started_at, 'completed_at' => $this->completed_at, 'recount_reason' => $this->recount_reason, 'is_current' => $this->is_current, 'version' => $this->version, 'denominations' => $this->whenLoaded('denominations', fn () => $this->denominations->map(fn ($line) => ['id' => $line->id, 'denomination_id' => $line->denomination_id, 'face_value' => (string) $line->face_value_snapshot, 'label' => $line->label_snapshot, 'quantity' => $line->quantity, 'line_amount' => (string) $line->line_amount, 'notes' => $line->notes])->values()), 'non_denomination_lines' => $this->whenLoaded('nonDenominations', fn () => $this->nonDenominations->map(fn ($line) => ['id' => $line->id, 'item_type' => $line->item_type, 'description' => $line->description, 'amount' => (string) $line->amount, 'notes' => $line->notes])->values()), 'confirmations' => $this->whenLoaded('confirmations', fn () => $this->confirmations->map(fn ($item) => ['type' => $item->confirmation_type, 'user_id' => $item->user_id, 'status' => $item->status, 'comments' => $item->comments, 'confirmed_at' => $item->confirmed_at])->values())];
    }
}
