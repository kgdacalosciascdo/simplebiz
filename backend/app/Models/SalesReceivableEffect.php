<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesReceivableEffect extends Model
{
    use HasUuids;

    protected $table = 'sales_receivable_effects';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['amount_delta' => 'decimal:6'];
    }

    public function receivable()
    {
        return $this->belongsTo(ReceivableOpenItem::class, 'receivable_open_item_id');
    }
}
