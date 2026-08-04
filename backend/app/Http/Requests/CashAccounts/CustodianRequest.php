<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class CustodianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['user_id' => ['required', 'integer'], 'responsibility_type' => ['nullable', 'string', 'max:40'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'is_primary' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string'], 'reason' => ['nullable', 'string', 'max:1000']];
    }
}
