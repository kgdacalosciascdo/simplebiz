<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExpenseAdjustmentEntry extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'effective_date' => 'date', 'metadata' => 'array'];
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function obligation()
    {
        return $this->belongsTo(ExpenseObligation::class, 'expense_obligation_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class);
    }

    public function accountingTransaction()
    {
        return $this->belongsTo(AccountingTransaction::class);
    }
}
