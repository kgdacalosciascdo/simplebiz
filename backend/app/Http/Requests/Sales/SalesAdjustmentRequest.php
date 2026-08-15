<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class SalesAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'uuid'],
            'adjustment_type' => ['required', 'in:debit,credit'],
            'adjustment_date' => ['required', 'date'],
            'reason_code_id' => ['nullable', 'uuid'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'explanation' => ['required', 'string', 'max:5000'],
            'version' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['required', 'string', 'max:240'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.unit_amount' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
