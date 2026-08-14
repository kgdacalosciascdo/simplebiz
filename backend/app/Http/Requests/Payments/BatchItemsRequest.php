<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class BatchItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['action' => ['required', 'in:add,remove'], 'payment_ids' => ['required', 'array', 'min:1', 'max:100'], 'payment_ids.*' => ['required', 'uuid'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
