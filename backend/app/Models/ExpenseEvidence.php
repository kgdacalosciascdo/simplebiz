<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExpenseEvidence extends Model
{
    use HasUuids;

    protected $table = 'expense_evidences';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['receipt_date' => 'date'];
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function attachment()
    {
        return $this->belongsTo(Attachment::class);
    }
}
