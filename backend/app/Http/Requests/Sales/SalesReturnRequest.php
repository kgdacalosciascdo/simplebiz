<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class SalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'uuid'],
            'return_date' => ['required', 'date'],
            'reason_code_id' => ['nullable', 'uuid'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'explanation' => ['required', 'string', 'max:5000'],
            'version' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
