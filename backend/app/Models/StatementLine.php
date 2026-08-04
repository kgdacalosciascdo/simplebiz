<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StatementLine extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date', 'value_date' => 'date', 'posting_date' => 'date',
            'debit_amount' => 'decimal:6', 'credit_amount' => 'decimal:6', 'signed_amount' => 'decimal:6', 'running_balance' => 'decimal:6',
            'raw_snapshot' => 'array', 'validation_errors' => 'array', 'version' => 'integer',
        ];
    }

    public function batch()
    {
        return $this->belongsTo(StatementImportBatch::class, 'statement_import_batch_id');
    }

    public function account()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }
}
