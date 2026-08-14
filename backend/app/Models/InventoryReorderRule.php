<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryReorderRule extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['reorder_point' => 'decimal:6', 'minimum_quantity' => 'decimal:6', 'target_quantity' => 'decimal:6', 'suggested_quantity' => 'decimal:6', 'effective_from' => 'date', 'effective_to' => 'date', 'version' => 'integer'];
    }

    public function productService()
    {
        return $this->belongsTo(ProductService::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockLocation()
    {
        return $this->belongsTo(StockLocation::class);
    }
}
