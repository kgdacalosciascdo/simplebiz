<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['supplier_id' => ['required', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'currency_id' => ['required', 'uuid'], 'payment_method_id' => ['required', 'uuid'], 'cash_account_id' => ['required', 'uuid'], 'payment_date' => ['required', 'date'], 'scheduled_date' => ['nullable', 'date', 'after_or_equal:payment_date'], 'reference' => ['nullable', 'string', 'max:1000'], 'remittance_details' => ['nullable', 'string', 'max:4000'], 'evidence_reference' => ['nullable', 'string', 'max:4000'], 'duplicate_override' => ['sometimes', 'boolean'], 'duplicate_override_reason' => ['nullable', 'string', 'max:4000'], 'sources' => ['required', 'array', 'min:1'], 'sources.*.payable_open_item_id' => ['required', 'uuid'], 'sources.*.amount' => ['required', 'numeric', 'gt:0'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
