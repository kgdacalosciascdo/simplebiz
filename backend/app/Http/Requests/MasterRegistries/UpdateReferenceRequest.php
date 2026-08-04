<?php

namespace App\Http\Requests\MasterRegistries;

class UpdateReferenceRequest extends StoreReferenceRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = array_merge(['sometimes'], $fieldRules);
        }
        $rules['version'] = ['required', 'integer', 'min:1'];

        return $rules;
    }
}
