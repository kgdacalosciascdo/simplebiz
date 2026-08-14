<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CollectionActivity extends Model
{
    use HasUuids;

    protected $table = 'collection_activities';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'next_action_at' => 'datetime', 'promise_date' => 'date', 'promise_amount' => 'decimal:6', 'version' => 'integer'];
    }

    public function customer()
    {
        return $this->belongsTo(BusinessPartner::class, 'customer_id');
    }

    public function receipt()
    {
        return $this->belongsTo(Receipt::class);
    }

    public function receivable()
    {
        return $this->belongsTo(ReceivableOpenItem::class, 'receivable_open_item_id');
    }
}
