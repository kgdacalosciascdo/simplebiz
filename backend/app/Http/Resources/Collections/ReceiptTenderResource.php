<?php

namespace App\Http\Resources\Collections;

use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptTenderResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'payment_method_id' => $this->payment_method_id, 'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod ? ['id' => $this->paymentMethod->id, 'code' => $this->paymentMethod->code, 'name' => $this->paymentMethod->name] : null), 'cash_account_id' => $this->cash_account_id, 'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? ['id' => $this->cashAccount->id, 'name' => $this->cashAccount->name, 'currency_id' => $this->cashAccount->currency_id] : null), 'amount' => $this->amount, 'instrument_status' => $this->instrument_status, 'clearing_status' => $this->clearing_status, 'external_reference' => $this->external_reference, 'instrument_reference' => $this->instrument_reference];
    }
}
