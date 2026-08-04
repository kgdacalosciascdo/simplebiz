<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReferenceCurrency extends Model
{
    use HasUuids;

    protected $table = 'reference_currencies';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decimal_precision' => 'integer', 'system_standard' => 'boolean', 'locked' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'version' => 'integer'];
    }
}
