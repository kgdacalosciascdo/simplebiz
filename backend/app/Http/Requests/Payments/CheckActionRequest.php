<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class CheckActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:4000'], 'evidence_reference' => ['nullable', 'string', 'max:4000'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
