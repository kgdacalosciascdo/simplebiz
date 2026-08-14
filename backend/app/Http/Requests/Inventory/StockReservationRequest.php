<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StockReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_type' => ['required', 'string', 'max:100'],
            'source_id' => ['required', 'string', 'max:120'],
            'source_line_id' => ['required', 'string', 'max:120'],
            'source_document_number' => ['nullable', 'string', 'max:80'],
            'product_service_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'stock_location_id' => ['required', 'uuid'],
            'unit_of_measure_id' => ['nullable', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
