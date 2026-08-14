<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'allocation_date' => 'date', 'reversed_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(PaymentInstruction::class, 'payment_instruction_id');
    }

    public function confirmation()
    {
        return $this->belongsTo(PaymentConfirmation::class, 'payment_confirmation_id');
    }

    public function payable()
    {
        return $this->belongsTo(PayableOpenItem::class, 'payable_open_item_id');
    }

    public function expenseObligation()
    {
        return $this->belongsTo(ExpenseObligation::class, 'expense_obligation_id');
    }

    public function reimbursementObligation()
    {
        return $this->belongsTo(ReimbursementObligation::class, 'reimbursement_obligation_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_allocation_id');
    }

    public function advance()
    {
        return $this->belongsTo(PaymentAdvance::class, 'payment_advance_id');
    }
}
