<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class TransitionCashAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000'], 'restricted_capabilities' => ['nullable', 'array'], 'restricted_capabilities.*' => ['string']];
    }
}
