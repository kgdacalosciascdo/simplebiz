<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AccountingTransaction extends Model
{
    use HasUuids;

    protected $table = 'accounting_transactions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['business_date' => 'date'];
    }

    public function lines()
    {
        return $this->hasMany(AccountingTransactionLine::class);
    }
}
