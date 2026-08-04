<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AccountingTransactionLine extends Model
{
    use HasUuids;

    protected $table = 'accounting_transaction_lines';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:6', 'credit' => 'decimal:6'];
    }
}
