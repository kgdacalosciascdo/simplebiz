<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSchedule extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'owner_id', 'report_definition_id', 'definition_version', 'name', 'recurrence', 'timezone', 'run_time', 'day_of_week', 'day_of_month', 'parameters', 'output_format', 'delivery_channel', 'recipient_user_ids', 'starts_at', 'ends_at', 'next_run_at', 'status', 'failure_code', 'failure_message', 'paused_at', 'cancelled_at', 'expired_at', 'last_run_at', 'version', 'created_by', 'updated_by', 'correlation_id'];

    protected function casts(): array
    {
        return ['definition_version' => 'integer', 'day_of_week' => 'integer', 'day_of_month' => 'integer', 'parameters' => 'array', 'recipient_user_ids' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'next_run_at' => 'datetime', 'paused_at' => 'datetime', 'cancelled_at' => 'datetime', 'expired_at' => 'datetime', 'last_run_at' => 'datetime', 'version' => 'integer'];
    }

    public function definition()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function occurrences()
    {
        return $this->hasMany(ReportScheduleOccurrence::class, 'schedule_id');
    }
}
