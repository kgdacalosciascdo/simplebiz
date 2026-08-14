<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_type' => ['nullable', Rule::in(['purchase_order', 'direct_purchase'])],
            'supplier_id' => ['required', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'currency_id' => ['required', 'uuid'], 'payment_term_id' => ['nullable', 'uuid'],
            'purchase_date' => ['required', 'date'], 'required_date' => ['nullable', 'date', 'after_or_equal:purchase_date'], 'supplier_reference' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'], 'reason' => ['nullable', 'string', 'max:5000'], 'evidence_reference' => ['nullable', 'string', 'max:255'], 'version' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_service_id' => ['required', 'uuid'], 'lines.*.unit_of_measure_id' => ['nullable', 'uuid'],
            'lines.*.warehouse_id' => ['nullable', 'uuid'], 'lines.*.stock_location_id' => ['nullable', 'uuid'], 'lines.*.description' => ['nullable', 'string', 'max:240'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_cost' => ['required', 'numeric', 'min:0'], 'lines.*.discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'], 'lines.*.tax_code_id' => ['nullable', 'uuid'],
        ];
    }
}
