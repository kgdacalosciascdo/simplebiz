<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    use HasUuids;

    protected $table = 'cash_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected $hidden = ['account_identifier_encrypted'];

    protected function casts(): array
    {
        return ['account_identifier_encrypted' => 'encrypted', 'restricted_capabilities' => 'array', 'version' => 'integer', 'status_changed_at' => 'datetime', 'activated_at' => 'datetime', 'restricted_at' => 'datetime', 'deactivated_at' => 'datetime', 'reactivated_at' => 'datetime', 'last_activity_at' => 'datetime', 'last_count_at' => 'datetime', 'last_reconciliation_at' => 'datetime'];
    }

    public function type()
    {
        return $this->belongsTo(CashAccountType::class, 'cash_account_type_id');
    }

    public function accountTitle()
    {
        return $this->belongsTo(AccountTitle::class, 'account_title_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function institution()
    {
        return $this->belongsTo(CashAccountInstitution::class, 'institution_id');
    }

    public function capabilities()
    {
        return $this->hasMany(CashAccountCapability::class);
    }

    public function custodians()
    {
        return $this->hasMany(CashAccountCustodian::class);
    }

    public function openingBalances()
    {
        return $this->hasMany(OpeningBalance::class);
    }

    public function movements()
    {
        return $this->hasMany(CashMovement::class);
    }
}
