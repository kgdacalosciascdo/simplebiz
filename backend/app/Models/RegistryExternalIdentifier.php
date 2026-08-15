<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RegistryExternalIdentifier extends Model
{
    use HasUuids;

    protected $table = 'registry_external_identifiers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'status_changed_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
