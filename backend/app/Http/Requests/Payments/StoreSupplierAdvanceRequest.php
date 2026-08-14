<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['supplier_id' => ['required', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'currency_id' => ['required', 'uuid'], 'payment_method_id' => ['required', 'uuid'], 'cash_account_id' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0'], 'payment_date' => ['required', 'date'], 'scheduled_date' => ['nullable', 'date'], 'reference' => ['nullable', 'string', 'max:4000'], 'remittance_details' => ['nullable', 'string', 'max:4000'], 'evidence_reference' => ['nullable', 'string', 'max:4000'], 'reason' => ['nullable', 'string', 'max:4000'], 'duplicate_override' => ['sometimes', 'boolean'], 'duplicate_override_reason' => ['nullable', 'string', 'max:4000']];
    }
}
