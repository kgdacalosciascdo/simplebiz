<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SaleStatusHistory extends Model
{
    use HasUuids;

    protected $table = 'sale_status_histories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];
}
