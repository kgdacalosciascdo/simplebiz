<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReimbursementClaimExpense extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['claimed_amount' => 'decimal:6'];
    }

    public function claim()
    {
        return $this->belongsTo(ReimbursementClaim::class, 'reimbursement_claim_id');
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
}
