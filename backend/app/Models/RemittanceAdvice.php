<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RemittanceAdvice extends Model
{
    use HasUuids;

    protected $table = 'remittance_advices';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    public function payment()
    {
        return $this->belongsTo(PaymentInstruction::class, 'payment_instruction_id');
    }
}
