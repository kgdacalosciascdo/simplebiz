<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentInstrument extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['printed_at' => 'datetime', 'signed_at' => 'datetime', 'stopped_at' => 'datetime', 'voided_at' => 'datetime', 'stale_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(PaymentInstruction::class, 'payment_instruction_id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function attempts()
    {
        return $this->hasMany(PaymentExecutionAttempt::class);
    }

    public function replacement()
    {
        return $this->belongsTo(self::class, 'replaces_instrument_id');
    }

    public function replacedBy()
    {
        return $this->belongsTo(self::class, 'replaced_by_instrument_id');
    }
}
