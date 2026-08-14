<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['sale_type' => ['required', Rule::in(['credit_sale', 'cash_sale'])], 'payment_basis' => ['nullable', Rule::in(['credit', 'cash'])], 'sale_date' => ['required', 'date'], 'branch_id' => ['nullable', 'uuid'], 'customer_id' => ['nullable', 'uuid'], 'currency_id' => ['nullable', 'uuid'], 'payment_term_id' => ['nullable', 'uuid'], 'due_date' => ['nullable', 'date'], 'customer_reference' => ['nullable', 'string', 'max:160'], 'channel' => ['nullable', 'string', 'max:40'], 'notes' => ['nullable', 'string', 'max:5000'], 'document_discount_type' => ['nullable', Rule::in(['percent', 'amount'])], 'document_discount_value' => ['nullable', 'numeric', 'min:0'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.product_service_id' => ['required', 'uuid'], 'lines.*.warehouse_id' => ['nullable', 'uuid'], 'lines.*.stock_location_id' => ['nullable', 'uuid'], 'lines.*.description' => ['nullable', 'string', 'max:240'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'], 'lines.*.price_override_reason' => ['nullable', 'string', 'max:500'], 'lines.*.discount_type' => ['nullable', Rule::in(['percent', 'amount'])], 'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'], 'lines.*.tax_code_id' => ['nullable', 'uuid'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
