<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashDenomination extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['face_value' => 'decimal:6', 'effective_from' => 'date', 'effective_to' => 'date', 'system_standard' => 'boolean', 'version' => 'integer'];
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }
}
