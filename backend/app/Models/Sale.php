<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasUuids;

    protected $table = 'sales';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sale_date' => 'date', 'due_date' => 'date', 'document_discount_value' => 'decimal:6', 'subtotal' => 'decimal:6', 'line_discount_total' => 'decimal:6', 'document_discount_total' => 'decimal:6', 'taxable_amount' => 'decimal:6', 'tax_total' => 'decimal:6', 'total' => 'decimal:6', 'paid_amount' => 'decimal:6', 'receivable_amount' => 'decimal:6', 'remaining_amount' => 'decimal:6', 'version' => 'integer', 'prepared_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(SaleLine::class);
    }

    public function customer()
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function paymentTerm()
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function receivable()
    {
        return $this->hasOne(ReceivableOpenItem::class, 'source_sale_id');
    }

    public function businessTransaction()
    {
        return $this->belongsTo(BusinessTransaction::class);
    }

    public function accountingTransaction()
    {
        return $this->belongsTo(AccountingTransaction::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(SaleStatusHistory::class)->latest();
    }

    public function inventoryMovements()
    {
        return $this->hasMany(StockMovement::class, 'source_id')->where('source_type', self::class);
    }

    public function salesReturns()
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function salesAdjustments()
    {
        return $this->hasMany(SalesAdjustment::class);
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'source_sale_id');
    }
}
