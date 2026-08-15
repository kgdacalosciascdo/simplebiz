<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use HasUuids;

    protected $table = 'sales_returns';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['return_date' => 'date', 'subtotal' => 'decimal:6', 'tax_amount' => 'decimal:6', 'total_amount' => 'decimal:6', 'customer_credit_amount' => 'decimal:6', 'version' => 'integer', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer()
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function lines()
    {
        return $this->hasMany(SalesReturnLine::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(SalesReturnStatusHistory::class);
    }
}
