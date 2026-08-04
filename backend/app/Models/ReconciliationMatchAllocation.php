<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReconciliationMatchAllocation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6'];
    }

    public function reconciliationMatch()
    {
        return $this->belongsTo(ReconciliationMatch::class, 'reconciliation_match_id');
    }

    public function statementLine()
    {
        return $this->belongsTo(StatementLine::class);
    }

    public function movement()
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }
}
