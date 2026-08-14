<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockReservationEvent extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function reservation()
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }
}
