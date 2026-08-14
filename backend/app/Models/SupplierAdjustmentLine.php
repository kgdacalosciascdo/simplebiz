<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupplierAdjustmentLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'unit_amount' => 'decimal:6', 'amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'total_amount' => 'decimal:6'];
    }

    public function adjustment()
    {
        return $this->belongsTo(SupplierAdjustment::class, 'supplier_adjustment_id');
    }
}
