<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    use HasUuids;

    protected $table = 'units_of_measure';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decimal_precision' => 'integer', 'allows_fractional' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'status_changed_at' => 'datetime', 'version' => 'integer'];
    }
}
