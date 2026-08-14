<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class ConvertPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['payment_method_id' => ['required', 'uuid'], 'cash_account_id' => ['required', 'uuid'], 'payment_date' => ['required', 'date'], 'scheduled_date' => ['nullable', 'date', 'after_or_equal:payment_date'], 'reference' => ['nullable', 'string', 'max:1000'], 'remittance_details' => ['nullable', 'string', 'max:4000'], 'evidence_reference' => ['nullable', 'string', 'max:4000']];
    }
}
