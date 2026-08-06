<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['version' => ['nullable', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:2000']];
    }
}
