<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['requested_amount' => 'decimal:6', 'requested_payment_date' => 'date', 'requested_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'version' => 'integer'];
    }

    public function supplier()
    {
        return $this->belongsTo(BusinessPartner::class, 'supplier_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function sources()
    {
        return $this->hasMany(PaymentRequestSource::class);
    }

    public function payment()
    {
        return $this->hasOne(PaymentInstruction::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(PaymentRequestStatusHistory::class);
    }
}
