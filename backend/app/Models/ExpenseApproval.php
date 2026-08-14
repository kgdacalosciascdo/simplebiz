<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExpenseApproval extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['authority_context' => 'array', 'acted_at' => 'datetime', 'submitted_version' => 'integer'];
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
}
