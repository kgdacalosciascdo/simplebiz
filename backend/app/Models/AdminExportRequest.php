<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AdminExportRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'requested_by', 'export_type', 'scope', 'purpose', 'status', 'package_payload',
        'ready_at', 'expires_at', 'downloaded_at', 'purged_at', 'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'package_payload' => 'array',
            'ready_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }
}
