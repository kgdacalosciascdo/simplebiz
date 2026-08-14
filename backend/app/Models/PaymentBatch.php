<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentBatch extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['control_total' => 'decimal:6', 'item_count' => 'integer', 'succeeded_count' => 'integer', 'failed_count' => 'integer', 'version' => 'integer', 'prepared_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'released_at' => 'datetime', 'generated_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(PaymentBatchItem::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function statusHistory()
    {
        return $this->hasMany(PaymentBatchStatusHistory::class);
    }
}
