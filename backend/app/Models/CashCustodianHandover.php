<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashCustodianHandover extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['handover_date' => 'date', 'outgoing_confirmed_at' => 'datetime', 'incoming_confirmed_at' => 'datetime', 'approved_at' => 'datetime', 'completed_at' => 'datetime', 'version' => 'integer'];
    }

    public function account()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function count()
    {
        return $this->belongsTo(CashCount::class, 'cash_count_id');
    }

    public function attempt()
    {
        return $this->belongsTo(CashCountAttempt::class, 'accepted_attempt_id');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'record');
    }
}
