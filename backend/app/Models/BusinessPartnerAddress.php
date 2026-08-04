<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BusinessPartnerAddress extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_primary_billing' => 'boolean', 'is_primary_shipping' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'version' => 'integer'];
    }
}
