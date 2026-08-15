<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesReturnStatusHistory extends Model
{
    use HasUuids;

    protected $table = 'sales_return_status_histories';

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';
}
