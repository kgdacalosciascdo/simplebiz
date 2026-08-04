<?php

namespace App\Http\Requests\MasterRegistries;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:120'], 'symbol' => ['nullable', 'string', 'max:24'], 'unit_type' => ['nullable', 'string', 'max:40'], 'decimal_precision' => ['nullable', 'integer', 'between:0,12'], 'allows_fractional' => ['nullable', 'boolean'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from']];
    }
}
