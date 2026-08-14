<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockTransferLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function sourceMovement()
    {
        return $this->belongsTo(StockMovement::class, 'source_movement_id');
    }

    public function destinationMovement()
    {
        return $this->belongsTo(StockMovement::class, 'destination_movement_id');
    }
}
