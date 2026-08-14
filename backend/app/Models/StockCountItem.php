<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockCountItem extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:6', 'counted_quantity' => 'decimal:6', 'variance_quantity' => 'decimal:6', 'recount_required' => 'boolean'];
    }

    public function count()
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function entries()
    {
        return $this->hasMany(StockCountEntry::class);
    }

    public function latestEntry()
    {
        return $this->belongsTo(StockCountEntry::class, 'latest_entry_id');
    }

    public function adjustment()
    {
        return $this->belongsTo(InventoryAdjustment::class, 'adjustment_id');
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
