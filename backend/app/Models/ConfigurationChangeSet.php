<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConfigurationChangeSet extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'created_by', 'domain', 'setting_key', 'version', 'status', 'scope',
        'proposed_values', 'previous_values', 'effective_values', 'validation_result', 'approval_result',
        'publication_result', 'reason', 'correlation_id', 'source_channel', 'effective_at', 'published_at', 'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'proposed_values' => 'array',
            'previous_values' => 'array',
            'effective_values' => 'array',
            'validation_result' => 'array',
            'approval_result' => 'array',
            'publication_result' => 'array',
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
