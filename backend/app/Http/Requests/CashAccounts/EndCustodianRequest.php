<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class EndCustodianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['effective_to' => ['nullable', 'date'], 'reason' => ['required', 'string', 'max:1000']];
    }
}
