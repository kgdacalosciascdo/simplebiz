<?php

namespace App\Http\Requests\CashAccounts;

class UpdateOpeningBalanceRequest extends StoreOpeningBalanceRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['version' => ['required', 'integer', 'min:1']]);
    }
}
