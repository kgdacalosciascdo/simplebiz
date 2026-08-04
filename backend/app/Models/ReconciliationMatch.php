<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReconciliationMatch extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tolerance_amount' => 'decimal:6', 'difference_amount' => 'decimal:6', 'confirmed_at' => 'datetime', 'unmatched_at' => 'datetime', 'version' => 'integer'];
    }

    public function allocations()
    {
        return $this->hasMany(ReconciliationMatchAllocation::class);
    }

    public function reconciliation()
    {
        return $this->belongsTo(Reconciliation::class);
    }
}
