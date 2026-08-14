<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExpenseImportRow extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['payload' => 'array', 'errors' => 'array'];
    }

    public function batch()
    {
        return $this->belongsTo(ExpenseImportBatch::class, 'batch_id');
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
}
