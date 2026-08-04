<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashMovementStatusHistory extends Model
{
    use HasUuids;

    protected $table = 'cash_movement_status_histories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];
}
