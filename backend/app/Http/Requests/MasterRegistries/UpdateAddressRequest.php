<?php

namespace App\Http\Requests\MasterRegistries;

class UpdateAddressRequest extends StoreAddressRequest
{
    public function rules(): array
    {
        return [...array_map(fn ($rules) => array_merge(['sometimes'], $rules), parent::rules()), 'version' => ['required', 'integer', 'min:1']];
    }
}
