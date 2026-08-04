<?php

namespace App\Http\Requests\MasterRegistries;

use Illuminate\Foundation\Http\FormRequest;

class StoreReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string'], 'symbol' => ['nullable', 'string', 'max:12'], 'decimal_precision' => ['nullable', 'integer', 'between:0,12'], 'method_class' => ['nullable', 'in:CASH,CHECK,BANK_TRANSFER,CARD,DIGITAL_WALLET,DIRECT_DEBIT,OTHER'], 'requires_external_reference' => ['nullable', 'boolean'], 'requires_account_selection' => ['nullable', 'boolean'], 'supports_incoming' => ['nullable', 'boolean'], 'supports_outgoing' => ['nullable', 'boolean'], 'clearing_behavior' => ['nullable', 'string', 'max:32'], 'term_type' => ['nullable', 'in:immediate,due_days,due_date'], 'due_days' => ['nullable', 'integer', 'min:0'], 'end_of_month' => ['nullable', 'boolean'], 'tax_type' => ['nullable', 'string', 'max:32'], 'rate' => ['nullable', 'numeric', 'between:0,100'], 'basis' => ['nullable', 'in:inclusive,exclusive'], 'recoverable' => ['nullable', 'boolean'], 'withholding' => ['nullable', 'boolean'], 'jurisdiction' => ['nullable', 'string', 'max:80'], 'classification' => ['nullable', 'in:asset,liability,equity,income,expense'], 'normal_balance' => ['nullable', 'in:debit,credit'], 'account_subtype' => ['nullable', 'string', 'max:80'], 'posting_eligible' => ['nullable', 'boolean'], 'branch_type' => ['nullable', 'string', 'max:40'], 'warehouse_type' => ['nullable', 'string', 'max:40'], 'branch_id' => ['nullable', 'uuid'], 'warehouse_id' => ['nullable', 'uuid'], 'parent_id' => ['nullable', 'uuid'], 'location_type' => ['nullable', 'string', 'max:40'], 'sellable' => ['nullable', 'boolean'], 'account_title_id' => ['nullable', 'uuid'], 'reporting_tag' => ['nullable', 'string', 'max:80'], 'domain' => ['nullable', 'string', 'max:48'], 'requires_explanation' => ['nullable', 'boolean'], 'requires_evidence' => ['nullable', 'boolean'], 'address_line1' => ['nullable', 'string', 'max:180'], 'address_line2' => ['nullable', 'string', 'max:180'], 'city' => ['nullable', 'string', 'max:120'], 'region' => ['nullable', 'string', 'max:120'], 'postal_code' => ['nullable', 'string', 'max:40'], 'country' => ['nullable', 'string', 'size:2'], 'contact_name' => ['nullable', 'string', 'max:160'], 'contact_email' => ['nullable', 'email', 'max:255'], 'contact_phone' => ['nullable', 'string', 'max:60'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from']];
    }
}
