<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoice extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'received_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:6', 'line_discount_total' => 'decimal:6', 'taxable_amount' => 'decimal:6', 'tax_total' => 'decimal:6', 'total' => 'decimal:6', 'paid_amount' => 'decimal:6', 'remaining_amount' => 'decimal:6', 'match_tolerance_amount' => 'decimal:6', 'version' => 'integer'];
    }

    public function supplier()
    {
        return $this->belongsTo(BusinessPartner::class, 'supplier_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines()
    {
        return $this->hasMany(SupplierInvoiceLine::class);
    }

    public function payable()
    {
        return $this->belongsTo(PayableOpenItem::class, 'payable_open_item_id');
    }

    public function matchExceptions()
    {
        return $this->hasMany(PurchaseMatchException::class);
    }

    public function matchHistory()
    {
        return $this->hasMany(PurchaseMatchHistory::class);
    }

    public function corrections()
    {
        return $this->hasMany(SupplierInvoiceCorrection::class, 'original_supplier_invoice_id');
    }
}
