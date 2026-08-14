<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['nullable', 'integer', 'min:1'],
            'business_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'uuid'],
            'payee_id' => ['nullable', 'uuid'],
            'payee_name' => ['nullable', 'string', 'max:180'],
            'external_reference' => ['nullable', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:4000'],
            'currency_id' => ['required', 'uuid'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'settlement_intent' => ['required', 'in:paid_now,pay_later,reimbursement'],
            'payment_term_id' => ['nullable', 'uuid'],
            'due_date' => ['nullable', 'date'],
            'approval_required' => ['nullable', 'boolean'],
            'evidence_required' => ['nullable', 'boolean'],
            'duplicate_override_reason' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.expense_category_id' => ['required', 'uuid'],
            'lines.*.expense_account_title_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['required', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_amount' => ['required', 'numeric', 'gte:0'],
            'lines.*.tax_code_id' => ['nullable', 'uuid'],
            'lines.*.branch_id' => ['nullable', 'uuid'],
            'lines.*.allocations' => ['nullable', 'array', 'max:20'],
            'lines.*.allocations.*.expense_account_title_id' => ['nullable', 'uuid'],
            'lines.*.allocations.*.expense_category_id' => ['nullable', 'uuid'],
            'lines.*.allocations.*.branch_id' => ['nullable', 'uuid'],
            'lines.*.allocations.*.allocation_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
        ];
    }
}
