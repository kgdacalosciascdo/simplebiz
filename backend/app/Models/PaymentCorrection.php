<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentCorrection extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'completed_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(PaymentInstruction::class, 'original_payment_id');
    }

    public function cashMovement()
    {
        return $this->belongsTo(CashMovement::class, 'reversal_cash_movement_id');
    }
}
