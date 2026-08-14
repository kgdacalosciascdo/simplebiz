<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentVoucher extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['reprint_count' => 'integer', 'issued_at' => 'datetime', 'last_reprinted_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(PaymentInstruction::class, 'payment_instruction_id');
    }
}
