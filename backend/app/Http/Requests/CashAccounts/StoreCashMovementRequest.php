<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['movement_purpose' => ['required', 'string', 'max:48'], 'cash_account_id' => ['required', 'uuid'], 'currency_id' => ['nullable', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0'], 'business_date' => ['required', 'date'], 'source_type' => ['required', 'string', 'max:64'], 'source_record_type' => ['nullable', 'string', 'max:160'], 'source_record_id' => ['nullable', 'string', 'max:120'], 'external_reference' => ['nullable', 'string', 'max:160'], 'payment_method_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'offset_account_title_id' => ['required', 'uuid'], 'reason_code_id' => ['required', 'uuid'], 'explanation' => ['required', 'string'], 'supporting_reference' => ['nullable', 'string'], 'password' => ['prohibited'], 'pin' => ['prohibited'], 'otp' => ['prohibited'], 'credentials' => ['prohibited'], 'api_key' => ['prohibited'], 'access_token' => ['prohibited'], 'private_key' => ['prohibited']];
    }
}
