<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashAccountCustodian extends Model
{
    use HasUuids;

    protected $table = 'cash_account_custodians';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'is_primary' => 'boolean', 'ended_at' => 'datetime', 'version' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
