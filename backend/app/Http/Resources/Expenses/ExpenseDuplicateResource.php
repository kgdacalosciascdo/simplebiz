<?php

namespace App\Http\Resources\Expenses;

use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseDuplicateResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'candidate_expense_id' => $this->candidate_expense_id, 'candidate_expense_number' => $this->whenLoaded('candidate', fn () => $this->candidate?->expense_number), 'risk_type' => $this->risk_type, 'score' => $this->score, 'status' => $this->status, 'resolution_reason' => $this->resolution_reason];
    }
}
