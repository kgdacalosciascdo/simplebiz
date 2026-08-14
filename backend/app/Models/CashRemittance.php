<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashRemittance extends Model
{
    use HasUuids;

    protected $table = 'cash_remittances';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['remittance_date' => 'date', 'period_start' => 'date', 'period_end' => 'date', 'expected_amount' => 'decimal:6', 'submitted_amount' => 'decimal:6', 'difference_amount' => 'decimal:6', 'prepared_at' => 'datetime', 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'accepted_at' => 'datetime', 'transfer_posted_at' => 'datetime', 'reversed_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines()
    {
        return $this->hasMany(CashRemittanceLine::class);
    }

    public function variances()
    {
        return $this->hasMany(RemittanceVariance::class);
    }

    public function sourceAccount()
    {
        return $this->belongsTo(CashAccount::class, 'source_cash_account_id');
    }

    public function destinationAccount()
    {
        return $this->belongsTo(CashAccount::class, 'destination_cash_account_id');
    }

    public function transfer()
    {
        return $this->belongsTo(CashTransferDocument::class, 'cash_transfer_document_id');
    }
}
