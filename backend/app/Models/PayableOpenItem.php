<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PayableOpenItem extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:6', 'paid_amount' => 'decimal:6', 'remaining_amount' => 'decimal:6', 'due_date' => 'date', 'discount_date' => 'date', 'version' => 'integer', 'last_calculated_at' => 'datetime'];
    }

    public function supplier()
    {
        return $this->belongsTo(BusinessPartner::class, 'supplier_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function sourceInvoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'source_supplier_invoice_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function effects()
    {
        return $this->hasMany(PayableEffect::class);
    }

    public function holdHistory()
    {
        return $this->hasMany(PayableHoldHistory::class);
    }
}
