<?php

namespace App\Http\Requests\MasterRegistries;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string'], 'record_type' => ['required', 'in:product,service'], 'category_id' => ['nullable', 'uuid'], 'base_unit_id' => ['nullable', 'uuid'], 'sellable' => ['nullable', 'boolean'], 'purchasable' => ['nullable', 'boolean'], 'stock_managed' => ['nullable', 'boolean'], 'non_stock' => ['nullable', 'boolean'], 'standard_selling_price' => ['nullable', 'numeric', 'min:0'], 'standard_purchase_price' => ['nullable', 'numeric', 'min:0'], 'tax_reference' => ['nullable', 'string', 'max:120'], 'barcode' => ['nullable', 'string', 'max:80'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'duplicate_override' => ['nullable', 'boolean'], 'duplicate_override_reason' => ['required_if:duplicate_override,true', 'string', 'max:500'], 'external_identifiers' => ['nullable', 'array']];
    }
}
