<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

class FailedTenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000'], 'failure_reference' => ['nullable', 'string', 'max:180'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
