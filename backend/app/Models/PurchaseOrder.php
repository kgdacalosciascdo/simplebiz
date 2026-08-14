<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['purchase_date' => 'date', 'required_date' => 'date', 'subtotal' => 'decimal:6', 'line_discount_total' => 'decimal:6', 'taxable_amount' => 'decimal:6', 'tax_total' => 'decimal:6', 'total' => 'decimal:6', 'received_amount' => 'decimal:6', 'invoiced_amount' => 'decimal:6', 'version' => 'integer'];
    }

    public function supplier()
    {
        return $this->belongsTo(BusinessPartner::class, 'supplier_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function paymentTerm()
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(PurchaseOrderStatusHistory::class);
    }

    public function revisions()
    {
        return $this->hasMany(PurchaseOrderRevision::class);
    }
}
