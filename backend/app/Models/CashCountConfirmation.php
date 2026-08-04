<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashCountConfirmation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function count()
    {
        return $this->belongsTo(CashCount::class, 'cash_count_id');
    }

    public function attempt()
    {
        return $this->belongsTo(CashCountAttempt::class, 'attempt_id');
    }
}
