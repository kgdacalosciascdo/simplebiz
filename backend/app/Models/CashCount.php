<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashCount extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['count_date' => 'date', 'cut_off_at' => 'datetime', 'expected_as_of_at' => 'datetime', 'count_started_at' => 'datetime', 'count_completed_at' => 'datetime', 'expected_snapshot_generated_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime', 'expected_amount' => 'decimal:6', 'actual_amount' => 'decimal:6', 'variance_amount' => 'decimal:6', 'scheduled' => 'boolean', 'recount_required' => 'boolean', 'post_cutoff_movement_ids' => 'array', 'version' => 'integer'];
    }

    public function account()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function type()
    {
        return $this->belongsTo(CashCountType::class, 'count_type_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function attempts()
    {
        return $this->hasMany(CashCountAttempt::class);
    }

    public function currentAttempt()
    {
        return $this->belongsTo(CashCountAttempt::class, 'current_attempt_id');
    }

    public function variance()
    {
        return $this->belongsTo(CashCountVariance::class, 'variance_id');
    }

    public function adjustment()
    {
        return $this->belongsTo(CashAdjustment::class, 'adjustment_id');
    }

    public function confirmations()
    {
        return $this->hasMany(CashCountConfirmation::class);
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'record');
    }
}
