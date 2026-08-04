<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Reconciliation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'version' => 'integer',
            'statement_opening_balance' => 'decimal:6', 'statement_closing_balance' => 'decimal:6', 'statement_inflows' => 'decimal:6', 'statement_outflows' => 'decimal:6',
            'internal_opening_balance' => 'decimal:6', 'internal_closing_balance' => 'decimal:6', 'internal_inflows' => 'decimal:6', 'internal_outflows' => 'decimal:6',
            'matched_statement_amount' => 'decimal:6', 'matched_movement_amount' => 'decimal:6', 'outstanding_statement_amount' => 'decimal:6', 'outstanding_movement_amount' => 'decimal:6', 'difference_amount' => 'decimal:6', 'adjustment_amount' => 'decimal:6',
            'prepared_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'completed_at' => 'datetime', 'locked_at' => 'datetime', 'reopened_at' => 'datetime',
        ];
    }

    public function account()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function batch()
    {
        return $this->belongsTo(StatementImportBatch::class, 'statement_import_batch_id');
    }

    public function matches()
    {
        return $this->hasMany(ReconciliationMatch::class);
    }

    public function outstandingItems()
    {
        return $this->hasMany(ReconciliationOutstandingItem::class);
    }

    public function history()
    {
        return $this->hasMany(ReconciliationHistory::class);
    }

    public function completions()
    {
        return $this->hasMany(ReconciliationCompletion::class);
    }
}
