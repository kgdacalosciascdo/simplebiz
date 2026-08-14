<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentConfirmation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['confirmed_amount' => 'decimal:6', 'confirmed_date' => 'date', 'manual_confirmation' => 'boolean', 'confirmed_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(PaymentInstruction::class, 'payment_instruction_id');
    }

    public function attempt()
    {
        return $this->belongsTo(PaymentExecutionAttempt::class, 'payment_execution_attempt_id');
    }
}
