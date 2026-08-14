<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['business_date' => 'date', 'blind' => 'boolean', 'snapshot_at' => 'datetime', 'cutoff_at' => 'datetime', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'closed_at' => 'datetime', 'version' => 'integer'];
    }

    public function items()
    {
        return $this->hasMany(StockCountItem::class);
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
