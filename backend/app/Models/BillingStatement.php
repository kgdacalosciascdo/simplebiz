<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingStatement extends Model
{
    use HasUuids;

    protected $table = 'billing_statements';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['statement_date' => 'date', 'period_from' => 'date', 'period_to' => 'date', 'as_of_at' => 'datetime', 'opening_balance' => 'decimal:6', 'period_charges' => 'decimal:6', 'period_credits' => 'decimal:6', 'period_applications' => 'decimal:6', 'ending_balance' => 'decimal:6', 'filters' => 'array', 'generated_at' => 'datetime', 'issued_at' => 'datetime', 'cancelled_at' => 'datetime', 'version' => 'integer'];
    }

    public function customer()
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function openItems()
    {
        return $this->belongsToMany(ReceivableOpenItem::class, 'billing_statement_open_items')->withPivot(['source_sale_id', 'included_amount'])->withTimestamps();
    }
}
