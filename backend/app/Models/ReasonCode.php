<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReasonCode extends Model
{
    use HasUuids;

    protected $table = 'reason_codes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requires_explanation' => 'boolean', 'requires_evidence' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'version' => 'integer'];
    }
}
