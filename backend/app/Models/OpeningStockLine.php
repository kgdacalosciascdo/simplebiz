<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OpeningStockLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function document()
    {
        return $this->belongsTo(OpeningStockDocument::class, 'opening_stock_document_id');
    }

    public function movement()
    {
        return $this->belongsTo(StockMovement::class);
    }
}
