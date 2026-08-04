<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashCountNonDenominationLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6'];
    }

    public function attempt()
    {
        return $this->belongsTo(CashCountAttempt::class, 'attempt_id');
    }
}
