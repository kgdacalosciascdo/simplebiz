<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReconciliationOutstandingItem extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'resolved_at' => 'datetime', 'version' => 'integer'];
    }

    public function reconciliation()
    {
        return $this->belongsTo(Reconciliation::class);
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
