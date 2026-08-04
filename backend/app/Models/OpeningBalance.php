<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OpeningBalance extends Model
{
    use HasUuids;

    protected $table = 'opening_balances';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'amount' => 'decimal:6', 'version' => 'integer', 'prepared_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'returned_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function account()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function reasonCode()
    {
        return $this->belongsTo(ReasonCode::class, 'reason_code_id');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'record', 'record_type', 'record_id');
    }

    public function movement()
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }
}
