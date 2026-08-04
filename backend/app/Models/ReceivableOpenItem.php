<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReceivableOpenItem extends Model
{
    use HasUuids;

    protected $table = 'receivable_open_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:6', 'applied_amount' => 'decimal:6', 'debit_adjustment_amount' => 'decimal:6', 'credit_adjustment_amount' => 'decimal:6', 'return_amount' => 'decimal:6', 'write_off_amount' => 'decimal:6', 'remaining_amount' => 'decimal:6', 'due_date' => 'date', 'last_calculated_at' => 'datetime', 'version' => 'integer'];
    }

    public function customer()
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function sourceSale()
    {
        return $this->belongsTo(Sale::class, 'source_sale_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function statements()
    {
        return $this->belongsToMany(BillingStatement::class, 'billing_statement_open_items')->withPivot(['source_sale_id', 'included_amount'])->withTimestamps();
    }
}
