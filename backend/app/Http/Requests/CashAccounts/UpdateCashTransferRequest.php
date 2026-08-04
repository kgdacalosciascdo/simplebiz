<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCashTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'purpose' => ['sometimes', 'in:INTERNAL_TRANSFER,DEPOSIT,WITHDRAWAL'], 'source_cash_account_id' => ['sometimes', 'uuid'], 'destination_cash_account_id' => ['sometimes', 'uuid'], 'currency_id' => ['nullable', 'uuid'], 'amount' => ['sometimes', 'numeric', 'gt:0'], 'business_date' => ['sometimes', 'date'], 'expected_completion_date' => ['nullable', 'date'], 'payment_method_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'reason_code_id' => ['sometimes', 'uuid'], 'external_reference' => ['nullable', 'string', 'max:160'], 'explanation' => ['sometimes', 'string'], 'supporting_reference' => ['nullable', 'string']];
    }
}
