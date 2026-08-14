<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentApproval extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['authority_context' => 'array', 'submitted_version' => 'integer', 'acted_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(PaymentInstruction::class, 'payment_instruction_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
