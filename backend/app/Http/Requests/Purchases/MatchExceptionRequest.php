<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class MatchExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['resolution' => ['required', 'string', 'max:3000'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
