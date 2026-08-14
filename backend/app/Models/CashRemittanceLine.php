<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashRemittanceLine extends Model
{
    use HasUuids;

    protected $table = 'cash_remittance_lines';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected_amount' => 'decimal:6', 'submitted_amount' => 'decimal:6', 'difference_amount' => 'decimal:6'];
    }

    public function remittance()
    {
        return $this->belongsTo(CashRemittance::class, 'cash_remittance_id');
    }

    public function tender()
    {
        return $this->belongsTo(ReceiptTender::class, 'receipt_tender_id');
    }
}
