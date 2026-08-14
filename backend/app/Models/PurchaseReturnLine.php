<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturnLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['original_received_quantity' => 'decimal:6', 'previously_returned_quantity' => 'decimal:6', 'quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'total_cost' => 'decimal:6', 'stock_managed_snapshot' => 'boolean'];
    }

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function goodsReceiptLine()
    {
        return $this->belongsTo(GoodsReceiptLine::class);
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
