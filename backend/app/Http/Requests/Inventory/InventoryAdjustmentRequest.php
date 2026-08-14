<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_type' => ['nullable', 'string', 'max:64'],
            'source_reference' => ['nullable', 'string', 'max:180'],
            'business_date' => ['required', 'date'],
            'reason_code_id' => ['required', 'uuid'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'explanation' => ['required', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_service_id' => ['required', 'uuid'],
            'lines.*.warehouse_id' => ['required', 'uuid'],
            'lines.*.stock_location_id' => ['required', 'uuid'],
            'lines.*.unit_of_measure_id' => ['nullable', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.direction' => ['required', Rule::in(['in', 'out'])],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.currency_code' => ['nullable', 'string', 'max:12'],
            'lines.*.cost_source' => ['nullable', 'string', 'max:80'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
