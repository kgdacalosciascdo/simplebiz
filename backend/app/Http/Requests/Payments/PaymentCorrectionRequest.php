<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class PaymentCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:4000'], 'evidence_reference' => ['nullable', 'string', 'max:4000'], 'correction_date' => ['nullable', 'date'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
