<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReceiptStatusHistory extends Model
{
    use HasUuids;

    protected $table = 'receipt_status_histories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    public function receipt()
    {
        return $this->belongsTo(Receipt::class);
    }
}
