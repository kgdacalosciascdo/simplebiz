<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class AdvanceApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['payable_open_item_id' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:4000'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
