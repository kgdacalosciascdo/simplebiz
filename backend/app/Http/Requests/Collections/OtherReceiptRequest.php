<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

class OtherReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'other_receipt_type' => ['required', 'string', 'max:60'],
            'receipt_date' => ['required', 'date'],
            'currency_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'counterparty_name' => ['required', 'string', 'max:180'],
            'source_module' => ['nullable', 'string', 'max:80'],
            'source_reference' => ['nullable', 'string', 'max:160'],
            'business_purpose' => ['required', 'string', 'max:500'],
            'evidence_reference' => ['required', 'string', 'max:180'],
            'external_reference' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'tenders' => ['required', 'array', 'min:1'],
            'tenders.*.payment_method_id' => ['required', 'uuid'],
            'tenders.*.cash_account_id' => ['required', 'uuid'],
            'tenders.*.amount' => ['required', 'numeric', 'gt:0'],
            'tenders.*.external_reference' => ['nullable', 'string', 'max:160'],
            'tenders.*.instrument_reference' => ['nullable', 'string', 'max:160'],
            'tenders.*.value_date' => ['nullable', 'date'],
            'tenders.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
