<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_date' => ['required', 'date'],
            'mode' => ['required', Rule::in(['full', 'cycle', 'spot'])],
            'warehouse_id' => ['required', 'uuid'],
            'stock_location_id' => ['required', 'uuid'],
            'blind' => ['sometimes', 'boolean'],
            'all_products' => ['sometimes', 'boolean'],
            'product_service_ids' => ['nullable', 'array', 'min:1'],
            'product_service_ids.*' => ['uuid'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
