<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExpenseObligation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:6', 'paid_amount' => 'decimal:6', 'credited_amount' => 'decimal:6', 'refunded_amount' => 'decimal:6', 'adjusted_amount' => 'decimal:6', 'remaining_amount' => 'decimal:6', 'due_date' => 'date', 'payment_ready' => 'boolean', 'version' => 'integer'];
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function payee()
    {
        return $this->belongsTo(BusinessPartner::class, 'payee_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class);
    }

    public function paymentSources()
    {
        return $this->hasMany(PaymentInstructionSource::class, 'expense_obligation_id');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class, 'expense_obligation_id');
    }
}
