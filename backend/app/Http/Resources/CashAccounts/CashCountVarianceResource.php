<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashCountVarianceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'cash_count_id' => $this->cash_count_id, 'accepted_attempt_id' => $this->accepted_attempt_id, 'expected_amount' => (string) $this->expected_amount, 'actual_amount' => (string) $this->actual_amount, 'variance_amount' => (string) $this->variance_amount, 'classification' => $this->classification, 'tolerance_amount' => (string) $this->tolerance_amount, 'within_tolerance' => $this->within_tolerance, 'status' => $this->status, 'disposition' => $this->disposition, 'disposition_reason' => $this->disposition_reason, 'owner_id' => $this->owner_id, 'adjustment_id' => $this->adjustment_id, 'version' => $this->version];
    }
}
