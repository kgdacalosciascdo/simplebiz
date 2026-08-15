<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductService extends Model
{
    use HasUuids;

    protected $table = 'products_services';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sellable' => 'boolean', 'purchasable' => 'boolean', 'stock_managed' => 'boolean', 'non_stock' => 'boolean', 'standard_selling_price' => 'decimal:4', 'standard_purchase_price' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date', 'status_changed_at' => 'datetime', 'version' => 'integer'];
    }

    public function category()
    {
        return $this->belongsTo(RegistryCategory::class, 'category_id');
    }

    public function baseUnit()
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_unit_id');
    }

    public function externalIdentifiers()
    {
        return $this->hasMany(RegistryExternalIdentifier::class, 'record_id')
            ->where('registry_type', 'product_service');
    }
}
