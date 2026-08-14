<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'discount_value' => 'decimal:6', 'gross_amount' => 'decimal:6', 'discount_amount' => 'decimal:6', 'taxable_amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'net_amount' => 'decimal:6', 'received_quantity' => 'decimal:6', 'invoiced_quantity' => 'decimal:6', 'cancelled_quantity' => 'decimal:6', 'stock_managed_snapshot' => 'boolean', 'non_stock_snapshot' => 'boolean', 'tax_rate' => 'decimal:6'];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
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
}
