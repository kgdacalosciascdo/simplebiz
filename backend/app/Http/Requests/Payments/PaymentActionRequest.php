<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class PaymentActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['version' => ['nullable', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:4000'], 'scheduled_date' => ['nullable', 'date'], 'external_reference' => ['nullable', 'string', 'max:180'], 'evidence_reference' => ['nullable', 'string', 'max:4000'], 'confirmed_amount' => ['nullable', 'numeric', 'gt:0'], 'confirmed_date' => ['nullable', 'date'], 'recipient_acknowledgement' => ['nullable', 'string', 'max:4000'], 'response_code' => ['nullable', 'string', 'max:80'], 'response_message' => ['nullable', 'string', 'max:4000']];
    }
}
