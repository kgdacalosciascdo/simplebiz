<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExpenseDuplicateCandidate extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['score' => 'integer', 'resolved_at' => 'datetime'];
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function candidate()
    {
        return $this->belongsTo(Expense::class, 'candidate_expense_id');
    }
}
