<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashTransferLeg extends Model
{
    use HasUuids;

    protected $table = 'cash_transfer_legs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    public function transfer()
    {
        return $this->belongsTo(CashTransferDocument::class, 'transfer_document_id');
    }

    public function movement()
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }

    public function account()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }
}
