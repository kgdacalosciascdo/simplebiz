<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

class SupplierInvoiceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['correction_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:5000'], 'evidence_reference' => ['nullable', 'string', 'max:255'], 'version' => ['nullable', 'integer', 'min:1']];
    }
}
