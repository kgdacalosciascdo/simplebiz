<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['nullable', 'string', 'max:180'], 'payment_ids' => ['required', 'array', 'min:1', 'max:100'], 'payment_ids.*' => ['required', 'uuid']];
    }
}
