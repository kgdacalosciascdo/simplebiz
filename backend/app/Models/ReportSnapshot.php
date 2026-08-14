<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSnapshot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'report_output_id', 'report_request_id', 'report_definition_id', 'definition_version', 'status', 'title', 'parameters', 'context', 'as_of_date', 'source_as_of_at', 'freshness_state', 'result_data', 'metadata', 'created_by', 'archived_at'];

    protected function casts(): array
    {
        return ['definition_version' => 'integer', 'parameters' => 'array', 'context' => 'array', 'as_of_date' => 'date', 'source_as_of_at' => 'datetime', 'result_data' => 'array', 'metadata' => 'array', 'archived_at' => 'datetime'];
    }

    public function definition()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    public function output()
    {
        return $this->belongsTo(ReportOutput::class, 'report_output_id');
    }
}
