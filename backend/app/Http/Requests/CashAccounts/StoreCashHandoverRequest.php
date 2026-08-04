<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashHandoverRequest extends FormRequest
{
    public function rules(): array
    {
        return ['cash_account_id' => ['required', 'uuid'], 'cash_count_id' => ['required', 'uuid'], 'accepted_attempt_id' => ['required', 'uuid'], 'incoming_custodian_id' => ['required', 'integer'], 'witness_id' => ['nullable', 'integer'], 'handover_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:5000']];
    }
}
