<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RecurringExpenseTemplate extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'amount' => 'decimal:6', 'next_run_date' => 'date', 'end_date' => 'date', 'line_defaults' => 'array'];
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class);
    }

    public function payee()
    {
        return $this->belongsTo(BusinessPartner::class, 'payee_id');
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function account()
    {
        return $this->belongsTo(AccountTitle::class, 'expense_account_title_id');
    }

    public function occurrences()
    {
        return $this->hasMany(RecurringExpenseOccurrence::class, 'template_id');
    }
}
