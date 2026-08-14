<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'accepted_quantity' => 'decimal:6', 'rejected_quantity' => 'decimal:6', 'damaged_quantity' => 'decimal:6', 'short_quantity' => 'decimal:6', 'over_quantity' => 'decimal:6', 'backordered_quantity' => 'decimal:6'];
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderLine()
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function productService()
    {
        return $this->belongsTo(ProductService::class);
    }
}
