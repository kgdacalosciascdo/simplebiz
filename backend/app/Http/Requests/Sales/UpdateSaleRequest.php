<?php

namespace App\Http\Requests\Sales;

class UpdateSaleRequest extends StoreSaleRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'sale_type' => ['sometimes', 'in:credit_sale,cash_sale'], 'sale_date' => ['sometimes', 'date'], 'lines' => ['sometimes', 'array', 'min:1']];
    }
}
