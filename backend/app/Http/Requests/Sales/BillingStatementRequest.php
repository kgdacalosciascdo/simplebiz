<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class BillingStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['customer_id' => ['required', 'uuid'], 'currency_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'statement_date' => ['required', 'date'], 'period_from' => ['nullable', 'date'], 'period_to' => ['nullable', 'date', 'after_or_equal:period_from']];
    }
}
