<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class AllocationCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:4000'], 'payable_open_item_id' => ['nullable', 'uuid'], 'amount' => ['nullable', 'numeric', 'gt:0'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
