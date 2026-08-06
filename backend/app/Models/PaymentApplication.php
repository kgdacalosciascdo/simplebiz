<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentApplication extends Model
{
    use HasUuids;

    protected $table = 'payment_applications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'application_date' => 'date', 'version' => 'integer', 'applied_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function receipt()
    {
        return $this->belongsTo(Receipt::class);
    }

    public function customer()
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function receivable()
    {
        return $this->belongsTo(ReceivableOpenItem::class, 'receivable_open_item_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class);
    }

    public function original()
    {
        return $this->belongsTo(self::class, 'original_application_id');
    }
}
