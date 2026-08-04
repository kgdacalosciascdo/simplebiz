<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashDenominationRequest extends FormRequest
{
    public function rules(): array
    {
        return ['currency_id' => ['required', 'uuid'], 'denomination_type' => ['required', 'in:NOTE,COIN,OTHER'], 'face_value' => ['required', 'numeric', 'gt:0'], 'display_label' => ['required', 'string', 'max:120'], 'sort_order' => ['nullable', 'integer', 'min:0'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from']];
    }
}
