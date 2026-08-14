<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseMatchHistoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'match_method' => $this->match_method, 'status' => $this->status, 'tolerance_amount' => (string) $this->tolerance_amount, 'variance_amount' => (string) $this->variance_amount, 'summary' => $this->summary, 'exceptions_snapshot' => $this->exceptions_snapshot, 'created_at' => $this->created_at];
    }
}
