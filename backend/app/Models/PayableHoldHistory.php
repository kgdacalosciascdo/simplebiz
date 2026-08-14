<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PayableHoldHistory extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    public function payable()
    {
        return $this->belongsTo(PayableOpenItem::class, 'payable_open_item_id');
    }
}
