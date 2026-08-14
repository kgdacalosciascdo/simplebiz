<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Foundation\Http\FormRequest;

class ExpensePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['amount' => ['nullable', 'numeric', 'gt:0'], 'payment_method_id' => ['required', 'uuid'], 'cash_account_id' => ['required', 'uuid'], 'payment_date' => ['required', 'date'], 'scheduled_date' => ['nullable', 'date', 'after_or_equal:payment_date'], 'reference' => ['nullable', 'string', 'max:1000']];
    }
}
