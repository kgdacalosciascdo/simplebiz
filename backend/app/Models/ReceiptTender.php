<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReceiptTender extends Model
{
    use HasUuids;

    protected $table = 'receipt_tenders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'value_date' => 'date', 'failed_at' => 'datetime', 'version' => 'integer'];
    }

    public function receipt()
    {
        return $this->belongsTo(Receipt::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class);
    }

    public function replacement()
    {
        return $this->belongsTo(self::class, 'replaced_by_tender_id');
    }
}
