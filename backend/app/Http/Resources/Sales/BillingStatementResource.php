<?php

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingStatementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'statement_number' => $this->statement_number, 'customer_id' => $this->customer_id, 'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'code' => $this->customer->code, 'display_name' => $this->customer->display_name] : null), 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => $this->currency ? ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol] : null), 'statement_date' => $this->statement_date?->toDateString(), 'period_from' => $this->period_from?->toDateString(), 'period_to' => $this->period_to?->toDateString(), 'as_of_at' => $this->as_of_at, 'status' => $this->status, 'opening_balance' => $this->opening_balance, 'period_charges' => $this->period_charges, 'period_credits' => $this->period_credits, 'period_applications' => $this->period_applications, 'ending_balance' => $this->ending_balance, 'filters' => $this->filters, 'open_items' => ReceivableResource::collection($this->whenLoaded('openItems')), 'version' => $this->version];
    }
}
