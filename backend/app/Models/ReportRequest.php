<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportRequest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'report_definition_id', 'definition_version', 'user_id', 'parameters', 'context', 'output_type', 'status', 'execution_mode', 'source_as_of_at', 'freshness_state', 'as_of_date', 'correlation_id', 'idempotency_identity', 'requested_at', 'validated_at', 'queued_at', 'queue_attempts', 'last_attempt_at', 'started_at', 'completed_at', 'failed_at', 'cancelled_at', 'cancelled_by', 'failure_code', 'failure_category', 'failure_message', 'retryable', 'failure_history', 'result_count'];

    protected function casts(): array
    {
        return ['definition_version' => 'integer', 'parameters' => 'array', 'context' => 'array', 'source_as_of_at' => 'datetime', 'as_of_date' => 'date', 'requested_at' => 'datetime', 'validated_at' => 'datetime', 'queued_at' => 'datetime', 'queue_attempts' => 'integer', 'last_attempt_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime', 'cancelled_at' => 'datetime', 'retryable' => 'boolean', 'failure_history' => 'array', 'result_count' => 'integer'];
    }

    public function definition()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    public function outputs()
    {
        return $this->hasMany(ReportOutput::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
