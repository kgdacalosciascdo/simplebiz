<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SaleLine extends Model
{
    use HasUuids;

    protected $table = 'sale_lines';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'gross_amount' => 'decimal:6', 'discount_value' => 'decimal:6', 'discount_amount' => 'decimal:6', 'tax_rate' => 'decimal:6', 'taxable_amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'net_amount' => 'decimal:6', 'stock_managed_snapshot' => 'boolean', 'non_stock_snapshot' => 'boolean', 'service_snapshot' => 'boolean', 'version' => 'integer'];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function productService()
    {
        return $this->belongsTo(ProductService::class);
    }

    public function taxCode()
    {
        return $this->belongsTo(TaxCode::class);
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
