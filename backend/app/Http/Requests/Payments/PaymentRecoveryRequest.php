<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['resolution' => ['required', 'in:retry,failed,rejected'], 'reason' => ['required', 'string', 'max:4000'], 'evidence_reference' => ['required', 'string', 'max:4000'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
