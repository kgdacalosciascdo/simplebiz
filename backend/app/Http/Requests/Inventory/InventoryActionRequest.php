<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class InventoryActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['version' => ['nullable', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:5000'], 'quantity' => ['nullable', 'numeric', 'gt:0']];
    }
}
