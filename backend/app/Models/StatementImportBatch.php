<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StatementImportBatch extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'imported_at' => 'datetime',
            'opening_statement_balance' => 'decimal:6', 'closing_statement_balance' => 'decimal:6',
            'total_debit' => 'decimal:6', 'total_credit' => 'decimal:6', 'validation_summary' => 'array', 'version' => 'integer',
        ];
    }

    public function account()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function lines()
    {
        return $this->hasMany(StatementLine::class);
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'record');
    }
}
