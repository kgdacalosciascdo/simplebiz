<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['receipt_type' => ['required', Rule::in(['customer_collection', 'advance_unapplied_customer_receipt', 'paid_now_sale_receipt'])], 'receipt_date' => ['required', 'date'], 'customer_id' => ['required', 'uuid'], 'source_sale_id' => ['nullable', 'uuid'], 'currency_id' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0'], 'external_reference' => ['nullable', 'string', 'max:160'], 'customer_reference' => ['nullable', 'string', 'max:160'], 'payer_name_snapshot' => ['nullable', 'string', 'max:180'], 'notes' => ['nullable', 'string', 'max:5000'], 'version' => ['nullable', 'integer', 'min:1'], 'tenders' => ['required', 'array', 'min:1'], 'tenders.*.payment_method_id' => ['required', 'uuid'], 'tenders.*.cash_account_id' => ['required', 'uuid'], 'tenders.*.amount' => ['required', 'numeric', 'gt:0'], 'tenders.*.external_reference' => ['nullable', 'string', 'max:160'], 'tenders.*.instrument_reference' => ['nullable', 'string', 'max:160'], 'tenders.*.value_date' => ['nullable', 'date'], 'tenders.*.notes' => ['nullable', 'string', 'max:1000'], 'applications' => ['nullable', 'array'], 'applications.*.receivable_open_item_id' => ['required', 'uuid'], 'applications.*.amount' => ['required', 'numeric', 'gt:0']];
    }
}
