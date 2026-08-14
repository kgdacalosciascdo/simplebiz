<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class SupplierAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'adjustment_type' => ['required', 'in:debit,credit'],
            'supplier_invoice_id' => ['nullable', 'uuid'],
            'purchase_return_id' => ['nullable', 'uuid'],
            'currency_id' => ['required', 'uuid'],
            'adjustment_date' => ['required', 'date'],
            'reason_code_id' => ['nullable', 'uuid'],
            'external_reference' => ['nullable', 'string', 'max:160'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'explanation' => ['required', 'string', 'max:5000'],
            'version' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.supplier_invoice_line_id' => ['nullable', 'uuid'],
            'lines.*.purchase_return_line_id' => ['nullable', 'uuid'],
            'lines.*.product_service_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['required', 'string', 'max:240'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.unit_amount' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
