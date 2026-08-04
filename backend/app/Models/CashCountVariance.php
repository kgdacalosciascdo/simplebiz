<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashCountVariance extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected_amount' => 'decimal:6', 'actual_amount' => 'decimal:6', 'variance_amount' => 'decimal:6', 'tolerance_amount' => 'decimal:6', 'within_tolerance' => 'boolean', 'version' => 'integer', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function count()
    {
        return $this->belongsTo(CashCount::class, 'cash_count_id');
    }

    public function attempt()
    {
        return $this->belongsTo(CashCountAttempt::class, 'accepted_attempt_id');
    }

    public function adjustment()
    {
        return $this->belongsTo(CashAdjustment::class, 'adjustment_id');
    }
}
