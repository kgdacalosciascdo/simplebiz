<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class SupplierInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid'], 'purchase_order_id' => ['nullable', 'uuid'], 'currency_id' => ['required', 'uuid'], 'payment_term_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'], 'external_invoice_number' => ['required', 'string', 'max:160'], 'invoice_date' => ['required', 'date'], 'received_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'], 'evidence_reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:5000'], 'match_tolerance_amount' => ['nullable', 'numeric', 'min:0'], 'version' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_service_id' => ['required', 'uuid'], 'lines.*.unit_of_measure_id' => ['nullable', 'uuid'],
            'lines.*.purchase_order_line_id' => ['nullable', 'uuid'], 'lines.*.goods_receipt_line_id' => ['nullable', 'uuid'], 'lines.*.description' => ['nullable', 'string', 'max:240'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_cost' => ['required', 'numeric', 'min:0'], 'lines.*.tax_code_id' => ['nullable', 'uuid'],
        ];
    }
}
