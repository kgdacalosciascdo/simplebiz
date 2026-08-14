<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryBalance extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['on_hand' => 'decimal:6', 'reserved' => 'decimal:6', 'incoming' => 'decimal:6', 'outgoing' => 'decimal:6', 'in_transit' => 'decimal:6', 'held' => 'decimal:6', 'count_frozen' => 'decimal:6', 'version' => 'integer', 'last_movement_at' => 'datetime'];
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

    public function unitOfMeasure()
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    public function getAvailableAttribute(): string
    {
        return bcsub(bcsub((string) $this->on_hand, (string) $this->reserved, 6), (string) $this->held, 6);
    }
}
