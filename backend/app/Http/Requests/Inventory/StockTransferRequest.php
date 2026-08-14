<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['source_warehouse_id' => ['required', 'uuid'], 'source_stock_location_id' => ['required', 'uuid'], 'destination_warehouse_id' => ['required', 'uuid'], 'destination_stock_location_id' => ['required', 'uuid'], 'business_date' => ['required', 'date'], 'reason_code_id' => ['nullable', 'uuid'], 'explanation' => ['required', 'string', 'max:5000'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.product_service_id' => ['required', 'uuid'], 'lines.*.unit_of_measure_id' => ['nullable', 'uuid'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
