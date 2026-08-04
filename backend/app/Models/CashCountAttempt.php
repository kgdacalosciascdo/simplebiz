<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashCountAttempt extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'actual_amount' => 'decimal:6', 'is_current' => 'boolean', 'version' => 'integer'];
    }

    public function count()
    {
        return $this->belongsTo(CashCount::class, 'cash_count_id');
    }

    public function denominations()
    {
        return $this->hasMany(CashCountDenominationLine::class, 'attempt_id');
    }

    public function nonDenominations()
    {
        return $this->hasMany(CashCountNonDenominationLine::class, 'attempt_id');
    }

    public function confirmations()
    {
        return $this->hasMany(CashCountConfirmation::class, 'attempt_id');
    }
}
