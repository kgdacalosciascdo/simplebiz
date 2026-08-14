<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['supplier_id' => ['required', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'currency_id' => ['required', 'uuid'], 'requested_amount' => ['nullable', 'numeric', 'gt:0'], 'requested_payment_date' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:4000'], 'evidence_reference' => ['nullable', 'string', 'max:4000'], 'sources' => ['required', 'array', 'min:1'], 'sources.*.payable_open_item_id' => ['required', 'uuid'], 'sources.*.amount' => ['required', 'numeric', 'gt:0']];
    }
}
