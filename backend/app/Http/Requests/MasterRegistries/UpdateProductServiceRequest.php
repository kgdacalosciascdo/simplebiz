<?php

namespace App\Http\Requests\MasterRegistries;

class UpdateProductServiceRequest extends StoreProductServiceRequest
{
    public function rules(): array
    {
        return [...array_map(fn ($rules) => array_merge(['sometimes'], $rules), parent::rules()), 'version' => ['required', 'integer', 'min:1']];
    }
}
