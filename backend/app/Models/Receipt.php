<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    use HasUuids;

    protected $table = 'receipts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['receipt_date' => 'date', 'amount' => 'decimal:6', 'tender_total' => 'decimal:6', 'applied_total' => 'decimal:6', 'unapplied_amount' => 'decimal:6', 'reversed_amount' => 'decimal:6', 'version' => 'integer', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'voided_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function sourceSale()
    {
        return $this->belongsTo(Sale::class, 'source_sale_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function tenders()
    {
        return $this->hasMany(ReceiptTender::class);
    }

    public function applications()
    {
        return $this->hasMany(PaymentApplication::class);
    }

    public function unapplied()
    {
        return $this->hasOne(CustomerUnappliedReceipt::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(ReceiptStatusHistory::class)->latest();
    }

    public function reprints()
    {
        return $this->hasMany(ReceiptReprint::class)->latest();
    }

    public function activities()
    {
        return $this->hasMany(CollectionActivity::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
