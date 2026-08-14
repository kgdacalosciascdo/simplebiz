<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupplierAdjustment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['adjustment_date' => 'date', 'amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'total_amount' => 'decimal:6', 'version' => 'integer', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function supplier()
    {
        return $this->belongsTo(BusinessPartner::class, 'supplier_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function supplierInvoice()
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function lines()
    {
        return $this->hasMany(SupplierAdjustmentLine::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(SupplierAdjustmentStatusHistory::class);
    }
}
