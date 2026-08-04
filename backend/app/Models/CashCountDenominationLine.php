<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashCountDenominationLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['face_value_snapshot' => 'decimal:6', 'quantity' => 'integer', 'line_amount' => 'decimal:6'];
    }

    public function attempt()
    {
        return $this->belongsTo(CashCountAttempt::class, 'attempt_id');
    }

    public function denomination()
    {
        return $this->belongsTo(CashDenomination::class, 'denomination_id');
    }
}
