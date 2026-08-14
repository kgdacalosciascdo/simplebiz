<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'uuid'], 'receipt_date' => ['required', 'date'], 'supplier_delivery_reference' => ['nullable', 'string', 'max:160'],
            'evidence_reference' => ['nullable', 'string', 'max:255'], 'explanation' => ['nullable', 'string', 'max:5000'], 'version' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.purchase_order_line_id' => ['required', 'uuid'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.accepted_quantity' => ['nullable', 'numeric', 'min:0'], 'lines.*.rejected_quantity' => ['nullable', 'numeric', 'min:0'], 'lines.*.damaged_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.warehouse_id' => ['nullable', 'uuid'], 'lines.*.stock_location_id' => ['nullable', 'uuid'], 'lines.*.reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
