<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashAccountType extends Model
{
    use HasUuids;

    protected $table = 'cash_account_types';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['default_capabilities' => 'array', 'allowed_capabilities' => 'array', 'requires_custodian' => 'boolean', 'supports_cash_count' => 'boolean', 'supports_reconciliation' => 'boolean', 'supports_statement_import' => 'boolean', 'supports_check' => 'boolean', 'system_standard' => 'boolean', 'locked' => 'boolean', 'version' => 'integer'];
    }
}
