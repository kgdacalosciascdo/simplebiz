<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class PostCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['negative_balance_override' => ['sometimes', 'boolean'], 'negative_balance_reason' => ['nullable', 'string', 'max:500']];
    }
}
