<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReconciliationHistory extends Model
{
    use HasUuids;

    protected $table = 'reconciliation_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer'];
    }
}
