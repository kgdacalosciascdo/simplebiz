<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'goods_receipt_id' => ['required', 'uuid'],
            'return_date' => ['required', 'date'],
            'reason_code_id' => ['nullable', 'uuid'],
            'supplier_authorization_reference' => ['nullable', 'string', 'max:160'],
            'shipping_reference' => ['nullable', 'string', 'max:160'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'explanation' => ['required', 'string', 'max:5000'],
            'version' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.goods_receipt_line_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.condition' => ['nullable', 'string', 'max:32'],
            'lines.*.reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
