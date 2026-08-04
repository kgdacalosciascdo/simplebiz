<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class DispositionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['disposition' => ['required', 'in:ADJUST_CASH,WAIVE_WITH_APPROVAL,INVESTIGATE,RECOUNT'], 'reason' => ['required', 'string', 'max:5000'], 'offset_account_title_id' => ['nullable', 'uuid'], 'reason_code_id' => ['nullable', 'uuid']];
    }
}
