<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentInstructionSource extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['requested_amount' => 'decimal:6', 'allocated_amount' => 'decimal:6'];
    }

    public function payment()
    {
        return $this->belongsTo(PaymentInstruction::class, 'payment_instruction_id');
    }

    public function payable()
    {
        return $this->belongsTo(PayableOpenItem::class, 'payable_open_item_id');
    }

    public function expenseObligation()
    {
        return $this->belongsTo(ExpenseObligation::class, 'expense_obligation_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function reimbursementObligation()
    {
        return $this->belongsTo(ReimbursementObligation::class, 'reimbursement_obligation_id');
    }
}
