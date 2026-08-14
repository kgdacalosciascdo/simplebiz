<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportOutput extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'report_request_id', 'report_definition_id', 'definition_version', 'format', 'status', 'storage_disk', 'storage_path', 'content_type', 'size_bytes', 'result_data', 'result_meta', 'expires_at', 'retention_expires_at', 'legal_hold', 'archived_at', 'purged_at', 'purge_reason', 'downloaded_at'];

    protected function casts(): array
    {
        return ['definition_version' => 'integer', 'size_bytes' => 'integer', 'result_data' => 'array', 'result_meta' => 'array', 'expires_at' => 'datetime', 'retention_expires_at' => 'datetime', 'legal_hold' => 'boolean', 'archived_at' => 'datetime', 'purged_at' => 'datetime', 'downloaded_at' => 'datetime'];
    }

    public function request()
    {
        return $this->belongsTo(ReportRequest::class, 'report_request_id');
    }

    public function definition()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }
}
