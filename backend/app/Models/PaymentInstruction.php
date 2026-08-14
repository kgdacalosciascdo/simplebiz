<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentInstruction extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['gross_amount' => 'decimal:6', 'discount_amount' => 'decimal:6', 'withholding_amount' => 'decimal:6', 'fee_amount' => 'decimal:6', 'tax_amount' => 'decimal:6', 'net_amount' => 'decimal:6', 'confirmed_amount' => 'decimal:6', 'allocated_amount' => 'decimal:6', 'unapplied_amount' => 'decimal:6', 'payment_date' => 'date', 'scheduled_date' => 'date', 'version' => 'integer', 'submitted_version' => 'integer', 'prepared_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'scheduled_at' => 'datetime', 'released_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function supplier()
    {
        return $this->belongsTo(BusinessPartner::class, 'supplier_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function request()
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function sources()
    {
        return $this->hasMany(PaymentInstructionSource::class);
    }

    public function approvals()
    {
        return $this->hasMany(PaymentApproval::class);
    }

    public function instruments()
    {
        return $this->hasMany(PaymentInstrument::class);
    }

    public function attempts()
    {
        return $this->hasMany(PaymentExecutionAttempt::class);
    }

    public function confirmations()
    {
        return $this->hasMany(PaymentConfirmation::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(PaymentStatusHistory::class);
    }

    public function remittanceAdvice()
    {
        return $this->hasOne(RemittanceAdvice::class);
    }

    public function corrections()
    {
        return $this->hasMany(PaymentCorrection::class, 'original_payment_id');
    }

    public function advance()
    {
        return $this->hasOne(PaymentAdvance::class);
    }

    public function batchItems()
    {
        return $this->hasMany(PaymentBatchItem::class);
    }

    public function voucher()
    {
        return $this->hasOne(PaymentVoucher::class);
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'record');
    }

    public function cashMovement()
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }
}
