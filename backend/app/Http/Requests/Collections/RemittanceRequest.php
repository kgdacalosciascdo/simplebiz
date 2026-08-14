<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

class RemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cashier_id' => ['nullable', 'integer'],
            'collector_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'uuid'],
            'currency_id' => ['required', 'uuid'],
            'destination_cash_account_id' => ['nullable', 'uuid'],
            'remittance_date' => ['required', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'tender_ids' => ['required', 'array', 'min:1'],
            'tender_ids.*' => ['required', 'uuid'],
            'submitted_amounts' => ['nullable', 'array'],
            'submitted_amounts.*' => ['numeric', 'gte:0'],
            'evidence_reference' => ['nullable', 'string', 'max:180'],
            'deposit_reference' => ['nullable', 'string', 'max:180'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
