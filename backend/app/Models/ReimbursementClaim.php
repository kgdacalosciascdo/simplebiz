<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReimbursementClaim extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['approval_required' => 'boolean', 'evidence_required' => 'boolean', 'total' => 'decimal:6', 'paid_amount' => 'decimal:6', 'remaining_amount' => 'decimal:6', 'due_date' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'version' => 'integer'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function claimant()
    {
        return $this->belongsTo(User::class, 'claimant_user_id');
    }

    public function claimantBusinessPartner()
    {
        return $this->belongsTo(BusinessPartner::class, 'claimant_business_partner_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class);
    }

    public function expenses()
    {
        return $this->belongsToMany(Expense::class, 'reimbursement_claim_expenses')->withPivot(['claimed_amount', 'status'])->withTimestamps();
    }

    public function claimExpenses()
    {
        return $this->hasMany(ReimbursementClaimExpense::class);
    }

    public function approvals()
    {
        return $this->hasMany(ReimbursementApproval::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(ReimbursementStatusHistory::class);
    }

    public function obligation()
    {
        return $this->hasOne(ReimbursementObligation::class);
    }
}
