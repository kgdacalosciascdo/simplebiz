<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'consumed_quantity' => 'decimal:6', 'released_quantity' => 'decimal:6', 'expires_at' => 'datetime', 'version' => 'integer'];
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

    public function events()
    {
        return $this->hasMany(StockReservationEvent::class);
    }

    public function getRemainingAttribute(): string
    {
        return bcsub(bcsub((string) $this->quantity, (string) $this->consumed_quantity, 6), (string) $this->released_quantity, 6);
    }
}
