<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashCountRequest extends FormRequest
{
    public function rules(): array
    {
        return ['cash_account_id' => ['required', 'uuid'], 'count_type' => ['required', 'string', 'max:40'], 'currency_id' => ['required', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'count_date' => ['required', 'date'], 'cut_off_at' => ['required', 'date'], 'post_cutoff_policy' => ['nullable', 'in:detect_and_review,freeze'], 'count_reason' => ['nullable', 'string', 'max:80'], 'scheduled' => ['nullable', 'boolean'], 'counter_id' => ['nullable', 'integer'], 'witness_id' => ['nullable', 'integer'], 'incoming_custodian_id' => ['nullable', 'integer'], 'outgoing_custodian_id' => ['nullable', 'integer'], 'reason_code_id' => ['required', 'uuid'], 'explanation' => ['required', 'string', 'max:5000']];
    }
}
