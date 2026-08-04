<?php

namespace App\Http\Resources\MasterRegistries;

use App\Models\AccountTitle;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\ReasonCode;
use App\Models\ReferenceCurrency;
use App\Models\StockLocation;
use App\Models\TaxCode;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $base = ['id' => $this->id, 'company_id' => $this->company_id, 'code' => $this->code, 'name' => $this->name, 'description' => $this->description ?? null, 'status' => $this->status, 'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(), 'version' => $this->version, 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
        if ($this->resource instanceof ReferenceCurrency) {
            $base += ['symbol' => $this->symbol, 'decimal_precision' => $this->decimal_precision, 'system_standard' => $this->system_standard, 'locked' => $this->locked];
        }
        if ($this->resource instanceof PaymentMethod) {
            $base += ['method_class' => $this->method_class, 'requires_external_reference' => $this->requires_external_reference, 'requires_account_selection' => $this->requires_account_selection, 'supports_incoming' => $this->supports_incoming, 'supports_outgoing' => $this->supports_outgoing, 'clearing_behavior' => $this->clearing_behavior];
        }
        if ($this->resource instanceof PaymentTerm) {
            $base += ['term_type' => $this->term_type, 'due_days' => $this->due_days, 'end_of_month' => $this->end_of_month];
        }
        if ($this->resource instanceof TaxCode) {
            $base += ['tax_type' => $this->tax_type, 'rate' => $this->rate, 'basis' => $this->basis, 'recoverable' => $this->recoverable, 'withholding' => $this->withholding, 'jurisdiction' => $this->jurisdiction];
        }
        if ($this->resource instanceof AccountTitle) {
            $base += ['classification' => $this->classification, 'normal_balance' => $this->normal_balance, 'account_subtype' => $this->account_subtype, 'posting_eligible' => $this->posting_eligible, 'system_standard' => $this->system_standard, 'locked' => $this->locked];
        }
        if ($this->resource instanceof ExpenseCategory) {
            $base += ['account_title_id' => $this->account_title_id, 'account_title' => $this->whenLoaded('accountTitle', fn () => $this->accountTitle ? ['id' => $this->accountTitle->id, 'code' => $this->accountTitle->code, 'name' => $this->accountTitle->name, 'classification' => $this->accountTitle->classification] : null), 'reporting_tag' => $this->reporting_tag];
        }
        if ($this->resource instanceof Branch) {
            $base += ['branch_type' => $this->branch_type, 'address_line1' => $this->address_line1, 'address_line2' => $this->address_line2, 'city' => $this->city, 'region' => $this->region, 'postal_code' => $this->postal_code, 'country' => $this->country, 'contact_name' => $this->contact_name, 'contact_email' => $this->contact_email, 'contact_phone' => $this->contact_phone];
        }
        if ($this->resource instanceof Warehouse) {
            $base += ['branch_id' => $this->branch_id, 'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name] : null), 'warehouse_type' => $this->warehouse_type, 'address_line1' => $this->address_line1, 'address_line2' => $this->address_line2, 'city' => $this->city, 'region' => $this->region, 'postal_code' => $this->postal_code, 'country' => $this->country];
        }
        if ($this->resource instanceof StockLocation) {
            $base += ['warehouse_id' => $this->warehouse_id, 'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? ['id' => $this->warehouse->id, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name] : null), 'parent_id' => $this->parent_id, 'location_type' => $this->location_type, 'sellable' => $this->sellable];
        }
        if ($this->resource instanceof ReasonCode) {
            $base += ['domain' => $this->domain, 'requires_explanation' => $this->requires_explanation, 'requires_evidence' => $this->requires_evidence];
        }

        return $base;
    }
}
