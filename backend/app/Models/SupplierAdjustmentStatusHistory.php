<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupplierAdjustmentStatusHistory extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    public function adjustment()
    {
        return $this->belongsTo(SupplierAdjustment::class, 'supplier_adjustment_id');
    }
}
