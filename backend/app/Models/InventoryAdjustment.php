<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryAdjustment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['business_date' => 'date', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines()
    {
        return $this->hasMany(InventoryAdjustmentLine::class);
    }

    public function reasonCode()
    {
        return $this->belongsTo(ReasonCode::class);
    }

    public function count()
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }
}
