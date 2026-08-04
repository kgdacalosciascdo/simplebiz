<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'movement_purpose' => ['sometimes', 'string', 'max:48'], 'cash_account_id' => ['sometimes', 'uuid'], 'currency_id' => ['nullable', 'uuid'], 'amount' => ['sometimes', 'numeric', 'gt:0'], 'business_date' => ['sometimes', 'date'], 'source_type' => ['sometimes', 'string', 'max:64'], 'source_record_type' => ['nullable', 'string', 'max:160'], 'source_record_id' => ['nullable', 'string', 'max:120'], 'external_reference' => ['nullable', 'string', 'max:160'], 'payment_method_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'offset_account_title_id' => ['sometimes', 'uuid'], 'reason_code_id' => ['sometimes', 'uuid'], 'explanation' => ['sometimes', 'string'], 'supporting_reference' => ['nullable', 'string']];
    }
}
