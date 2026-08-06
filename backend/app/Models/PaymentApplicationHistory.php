<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentApplicationHistory extends Model
{
    use HasUuids;

    protected $table = 'payment_application_histories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];
}
