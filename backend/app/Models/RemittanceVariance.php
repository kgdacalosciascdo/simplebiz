<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RemittanceVariance extends Model
{
    use HasUuids;

    protected $table = 'remittance_variances';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected_amount' => 'decimal:6', 'actual_amount' => 'decimal:6', 'difference_amount' => 'decimal:6', 'approved_at' => 'datetime', 'version' => 'integer'];
    }

    public function remittance()
    {
        return $this->belongsTo(CashRemittance::class, 'cash_remittance_id');
    }
}
