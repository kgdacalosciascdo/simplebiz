<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerUnappliedReceipt extends Model
{
    use HasUuids;

    protected $table = 'customer_unapplied_receipts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:6', 'applied_later_amount' => 'decimal:6', 'available_amount' => 'decimal:6', 'received_date' => 'date', 'version' => 'integer'];
    }

    public function receipt()
    {
        return $this->belongsTo(Receipt::class);
    }

    public function customer()
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class);
    }
}
