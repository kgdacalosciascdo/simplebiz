<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashAdjustment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'prepared_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'version' => 'integer'];
    }

    public function count()
    {
        return $this->belongsTo(CashCount::class, 'cash_count_id');
    }

    public function variance()
    {
        return $this->belongsTo(CashCountVariance::class, 'variance_id');
    }

    public function movementDocument()
    {
        return $this->belongsTo(CashMovementDocument::class, 'cash_movement_document_id');
    }

    public function movement()
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }
}
