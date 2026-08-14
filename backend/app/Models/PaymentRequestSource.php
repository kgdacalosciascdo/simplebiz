<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentRequestSource extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['proposed_amount' => 'decimal:6'];
    }

    public function request()
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function payable()
    {
        return $this->belongsTo(PayableOpenItem::class, 'payable_open_item_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }
}
