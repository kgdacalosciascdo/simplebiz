<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesReturnLine extends Model
{
    use HasUuids;

    protected $table = 'sales_return_lines';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['original_quantity' => 'decimal:6', 'previously_returned_quantity' => 'decimal:6', 'quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'taxable_amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'total_amount' => 'decimal:6', 'stock_managed_snapshot' => 'boolean', 'service_snapshot' => 'boolean'];
    }

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function saleLine()
    {
        return $this->belongsTo(SaleLine::class);
    }

    public function productService()
    {
        return $this->belongsTo(ProductService::class);
    }

    public function unitOfMeasure()
    {
        return $this->belongsTo(UnitOfMeasure::class);
    }

    public function inventoryMovement()
    {
        return $this->belongsTo(StockMovement::class, 'inventory_movement_id');
    }
}
