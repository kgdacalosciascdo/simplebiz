<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AccountTitle extends Model
{
    use HasUuids;

    protected $table = 'account_titles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['posting_eligible' => 'boolean', 'system_standard' => 'boolean', 'locked' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'version' => 'integer'];
    }

    public function expenseCategories()
    {
        return $this->hasMany(ExpenseCategory::class);
    }
}
