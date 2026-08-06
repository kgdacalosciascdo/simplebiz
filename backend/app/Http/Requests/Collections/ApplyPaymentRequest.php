<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

class ApplyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['unapplied_receipt_id' => ['required', 'uuid'], 'receivable_open_item_id' => ['required', 'uuid'], 'amount' => ['required', 'numeric', 'gt:0'], 'application_date' => ['required', 'date'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
