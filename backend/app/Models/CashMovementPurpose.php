<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashMovementPurpose extends Model
{
    use HasUuids;

    protected $table = 'cash_movement_purposes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requires_destination' => 'boolean', 'requires_payment_method' => 'boolean', 'requires_evidence' => 'boolean', 'approval_required' => 'boolean', 'reversal_allowed' => 'boolean', 'allowed_source_types' => 'array'];
    }
}
