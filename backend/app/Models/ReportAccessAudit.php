<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportAccessAudit extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'user_id', 'report_request_id', 'report_output_id', 'report_definition_id', 'definition_version', 'action', 'format', 'parameter_hash', 'source_modules', 'result_state', 'metadata', 'correlation_id', 'created_at'];

    protected function casts(): array
    {
        return ['definition_version' => 'integer', 'source_modules' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function definition()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }
}
