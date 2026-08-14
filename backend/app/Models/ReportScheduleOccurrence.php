<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportScheduleOccurrence extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'schedule_id', 'occurrence_key', 'scheduled_for', 'definition_version', 'parameters', 'status', 'report_request_id', 'report_output_id', 'started_at', 'completed_at', 'failed_at', 'failure_code', 'failure_message', 'attempts', 'correlation_id'];

    protected function casts(): array
    {
        return ['definition_version' => 'integer', 'parameters' => 'array', 'scheduled_for' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function schedule()
    {
        return $this->belongsTo(ReportSchedule::class, 'schedule_id');
    }

    public function request()
    {
        return $this->belongsTo(ReportRequest::class, 'report_request_id');
    }

    public function output()
    {
        return $this->belongsTo(ReportOutput::class, 'report_output_id');
    }
}
