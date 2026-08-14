<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RecurringExpenseOccurrence extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['scheduled_date' => 'date'];
    }

    public function template()
    {
        return $this->belongsTo(RecurringExpenseTemplate::class, 'template_id');
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
}
