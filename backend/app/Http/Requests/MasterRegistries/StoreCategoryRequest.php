<?php

namespace App\Http\Requests\MasterRegistries;

use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string'], 'parent_id' => ['nullable', 'uuid'], 'applicability' => ['nullable', 'in:product,service,both'], 'display_order' => ['nullable', 'integer', 'min:0'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from']];
    }
}
