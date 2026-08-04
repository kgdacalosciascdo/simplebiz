<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['cash_account_id' => ['required', 'uuid'], 'currency_id' => ['required', 'uuid'], 'effective_date' => ['required', 'date'], 'direction' => ['nullable', 'in:increase,decrease'], 'amount' => ['required', 'numeric', 'gt:0'], 'opening_source' => ['required', 'string', 'max:80'], 'migration_reference' => ['nullable', 'string', 'max:160'], 'offset_account_title_id' => ['nullable', 'uuid'], 'reason_code_id' => ['required', 'uuid'], 'explanation' => ['required', 'string'], 'batch_reference' => ['nullable', 'string', 'max:120']];
    }
}
