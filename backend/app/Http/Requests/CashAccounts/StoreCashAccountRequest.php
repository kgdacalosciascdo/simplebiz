<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'display_name' => ['nullable', 'string', 'max:180'],
            'cash_account_type_id' => ['required', 'uuid'],
            'account_title_id' => ['required', 'uuid'],
            'currency_id' => ['required', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'account_identifier' => ['nullable', 'string', 'max:180'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'account_subtype' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string'],
            'institution' => ['nullable', 'array'],
            'institution.provider_type' => ['required_with:institution', 'string', 'max:32'],
            'institution.name' => ['required_with:institution', 'string', 'max:160'],
            'institution.branch_name' => ['nullable', 'string', 'max:160'],
            'institution.routing_reference' => ['nullable', 'string', 'max:120'],
            'institution.statement_format' => ['nullable', 'string', 'max:40'],
            'password' => ['prohibited'],
            'pin' => ['prohibited'],
            'otp' => ['prohibited'],
            'credentials' => ['prohibited'],
            'private_key' => ['prohibited'],
            'api_key' => ['prohibited'],
            'access_token' => ['prohibited'],
        ];
    }
}
