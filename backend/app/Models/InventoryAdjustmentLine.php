<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryAdjustmentLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'expected_quantity' => 'decimal:6', 'resulting_quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'total_cost' => 'decimal:6'];
    }

    public function adjustment()
    {
        return $this->belongsTo(InventoryAdjustment::class);
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

    public function movement()
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function countItem()
    {
        return $this->belongsTo(StockCountItem::class, 'stock_count_item_id');
    }
}
