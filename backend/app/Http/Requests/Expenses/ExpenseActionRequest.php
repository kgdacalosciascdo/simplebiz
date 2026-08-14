<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseActionRequest extends FormRequest
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
