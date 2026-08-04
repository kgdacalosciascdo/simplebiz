<?php

namespace App\Http\Requests\CashAccounts;

class UpdateCashAccountRequest extends StoreCashAccountRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['version' => ['required', 'integer', 'min:1'], 'cash_account_type_id' => ['sometimes', 'uuid'], 'account_title_id' => ['sometimes', 'uuid'], 'currency_id' => ['sometimes', 'uuid']]);
    }
}
