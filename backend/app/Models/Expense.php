<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'due_date' => 'date',
            'approval_required' => 'boolean',
            'evidence_required' => 'boolean',
            'subtotal' => 'decimal:6',
            'taxable_amount' => 'decimal:6',
            'tax_amount' => 'decimal:6',
            'recoverable_tax_amount' => 'decimal:6',
            'nonrecoverable_tax_amount' => 'decimal:6',
            'withholding_amount' => 'decimal:6',
            'total' => 'decimal:6',
            'paid_amount' => 'decimal:6',
            'remaining_amount' => 'decimal:6',
            'exchange_rate' => 'decimal:10',
            'functional_subtotal' => 'decimal:6',
            'functional_tax_amount' => 'decimal:6',
            'functional_total' => 'decimal:6',
            'functional_paid_amount' => 'decimal:6',
            'functional_remaining_amount' => 'decimal:6',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function payee()
    {
        return $this->belongsTo(BusinessPartner::class, 'payee_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class);
    }

    public function paymentTerm()
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function lines()
    {
        return $this->hasMany(ExpenseLine::class);
    }

    public function allocations()
    {
        return $this->hasMany(ExpenseAllocation::class);
    }

    public function approvals()
    {
        return $this->hasMany(ExpenseApproval::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(ExpenseStatusHistory::class);
    }

    public function obligation()
    {
        return $this->hasOne(ExpenseObligation::class);
    }

    public function evidences()
    {
        return $this->hasMany(ExpenseEvidence::class);
    }

    public function duplicateCandidates()
    {
        return $this->hasMany(ExpenseDuplicateCandidate::class);
    }

    public function businessTransaction()
    {
        return $this->belongsTo(BusinessTransaction::class);
    }

    public function accountingTransaction()
    {
        return $this->belongsTo(AccountingTransaction::class);
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'record');
    }

    public function reimbursementClaim()
    {
        return $this->belongsTo(ReimbursementClaim::class, 'reimbursement_claim_id');
    }

    public function recurringTemplate()
    {
        return $this->belongsTo(RecurringExpenseTemplate::class, 'source_recurring_template_id');
    }

    public function recurringOccurrence()
    {
        return $this->belongsTo(RecurringExpenseOccurrence::class, 'source_recurring_occurrence_id');
    }

    public function copySource()
    {
        return $this->belongsTo(self::class, 'copy_source_expense_id');
    }

    public function adjustments()
    {
        return $this->hasMany(ExpenseAdjustmentEntry::class);
    }
}
