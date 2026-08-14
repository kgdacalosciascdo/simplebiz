<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class OpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['business_date' => ['required', 'date'], 'source_reference' => ['nullable', 'string', 'max:180'], 'explanation' => ['required', 'string', 'max:5000'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.product_service_id' => ['required', 'uuid'], 'lines.*.warehouse_id' => ['required', 'uuid'], 'lines.*.stock_location_id' => ['required', 'uuid'], 'lines.*.unit_of_measure_id' => ['nullable', 'uuid'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
