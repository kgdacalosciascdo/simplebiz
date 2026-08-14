<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExpenseLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'unit_amount' => 'decimal:6', 'line_amount' => 'decimal:6', 'taxable_amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'recoverable_tax_amount' => 'decimal:6', 'nonrecoverable_tax_amount' => 'decimal:6', 'withholding_amount' => 'decimal:6', 'line_total' => 'decimal:6', 'version' => 'integer'];
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function account()
    {
        return $this->belongsTo(AccountTitle::class, 'expense_account_title_id');
    }

    public function taxCode()
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function allocations()
    {
        return $this->hasMany(ExpenseAllocation::class);
    }
}
