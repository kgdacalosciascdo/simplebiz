<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExpenseAllocation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['allocation_percent' => 'decimal:6', 'amount' => 'decimal:6'];
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function line()
    {
        return $this->belongsTo(ExpenseLine::class, 'expense_line_id');
    }

    public function account()
    {
        return $this->belongsTo(AccountTitle::class, 'expense_account_title_id');
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
