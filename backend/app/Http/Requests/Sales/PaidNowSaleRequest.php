<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class PaidNowSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receipt_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method_id' => ['required', 'uuid'],
            'cash_account_id' => ['required', 'uuid'],
            'external_reference' => ['nullable', 'string', 'max:160'],
            'customer_reference' => ['nullable', 'string', 'max:160'],
            'instrument_reference' => ['nullable', 'string', 'max:160'],
            'value_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
