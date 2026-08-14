<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class PaymentAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['payment_confirmation_id' => ['nullable', 'uuid'], 'payable_open_item_id' => ['nullable', 'uuid', 'required_without_all:expense_obligation_id,reimbursement_obligation_id'], 'expense_obligation_id' => ['nullable', 'uuid', 'required_without_all:payable_open_item_id,reimbursement_obligation_id'], 'reimbursement_obligation_id' => ['nullable', 'uuid', 'required_without_all:payable_open_item_id,expense_obligation_id'], 'amount' => ['required', 'numeric', 'gt:0'], 'allocation_date' => ['required', 'date'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
