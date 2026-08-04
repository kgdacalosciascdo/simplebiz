<?php

namespace App\Http\Requests\MasterRegistries;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['address_type' => ['nullable', 'string', 'max:32'], 'line1' => ['required', 'string', 'max:180'], 'line2' => ['nullable', 'string', 'max:180'], 'city' => ['nullable', 'string', 'max:120'], 'region' => ['nullable', 'string', 'max:120'], 'postal_code' => ['nullable', 'string', 'max:40'], 'country' => ['nullable', 'string', 'size:2'], 'is_primary_billing' => ['nullable', 'boolean'], 'is_primary_shipping' => ['nullable', 'boolean'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from']];
    }
}
