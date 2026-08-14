<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockCountEntry extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_recount' => 'boolean', 'counted_quantity' => 'decimal:6', 'counted_at' => 'datetime', 'version' => 'integer'];
    }

    public function count()
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function item()
    {
        return $this->belongsTo(StockCountItem::class, 'stock_count_item_id');
    }
}
