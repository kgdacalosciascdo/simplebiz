<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class InventoryReorderRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['product_service_id' => ['required', 'uuid'], 'warehouse_id' => ['nullable', 'uuid'], 'stock_location_id' => ['nullable', 'uuid'], 'reorder_point' => ['required', 'numeric', 'gte:0'], 'minimum_quantity' => ['nullable', 'numeric', 'gte:0'], 'target_quantity' => ['nullable', 'numeric', 'gte:0'], 'suggested_quantity' => ['nullable', 'numeric', 'gte:0'], 'status' => ['nullable', 'in:active,inactive'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
