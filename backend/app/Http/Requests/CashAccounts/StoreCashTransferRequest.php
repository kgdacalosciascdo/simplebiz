<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['purpose' => ['required', 'in:INTERNAL_TRANSFER,DEPOSIT,WITHDRAWAL'], 'source_cash_account_id' => ['required', 'uuid'], 'destination_cash_account_id' => ['required', 'uuid', 'different:source_cash_account_id'], 'currency_id' => ['nullable', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0'], 'business_date' => ['required', 'date'], 'expected_completion_date' => ['nullable', 'date'], 'payment_method_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'reason_code_id' => ['required', 'uuid'], 'external_reference' => ['nullable', 'string', 'max:160'], 'explanation' => ['required', 'string'], 'supporting_reference' => ['nullable', 'string']];
    }
}
