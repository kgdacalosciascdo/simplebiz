<?php

namespace App\Http\Resources\MasterRegistries;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'code' => $this->code, 'name' => $this->name, 'description' => $this->description, 'record_type' => $this->record_type, 'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'code' => $this->category->code, 'name' => $this->category->name] : null), 'base_unit' => $this->whenLoaded('baseUnit', fn () => $this->baseUnit ? ['id' => $this->baseUnit->id, 'code' => $this->baseUnit->code, 'name' => $this->baseUnit->name, 'symbol' => $this->baseUnit->symbol] : null), 'category_id' => $this->category_id, 'base_unit_id' => $this->base_unit_id, 'sellable' => $this->sellable, 'purchasable' => $this->purchasable, 'stock_managed' => $this->stock_managed, 'non_stock' => $this->non_stock, 'standard_selling_price' => $this->standard_selling_price, 'standard_purchase_price' => $this->standard_purchase_price, 'tax_reference' => $this->tax_reference, 'barcode' => $this->barcode, 'status' => $this->status, 'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(), 'version' => $this->version, 'external_identifiers' => $this->whenLoaded('externalIdentifiers', fn () => RegistryExternalIdentifierResource::collection($this->externalIdentifiers)), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}
