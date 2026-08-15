<?php

namespace App\Http\Requests\MasterRegistries;

use Illuminate\Foundation\Http\FormRequest;

class StoreExternalIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier_type' => ['required', 'string', 'max:60'],
            'value' => ['required', 'string', 'max:180'],
            'source_system' => ['nullable', 'string', 'max:80'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
