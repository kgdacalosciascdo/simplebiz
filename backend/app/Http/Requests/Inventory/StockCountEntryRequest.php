<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StockCountEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['item_id' => ['required', 'uuid'], 'counted_quantity' => ['required', 'numeric', 'gte:0'], 'evidence_reference' => ['nullable', 'string', 'max:255'], 'explanation' => ['nullable', 'string', 'max:5000'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
