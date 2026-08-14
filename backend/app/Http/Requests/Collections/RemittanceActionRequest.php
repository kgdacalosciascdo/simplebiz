<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

class RemittanceActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:2000'], 'evidence_reference' => ['nullable', 'string', 'max:180'], 'resolution' => ['nullable', 'string', 'max:2000'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
