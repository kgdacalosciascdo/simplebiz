<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportDelivery extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'schedule_id', 'occurrence_id', 'report_output_id', 'recipient_user_id', 'channel', 'status', 'attempts', 'last_attempt_at', 'delivered_at', 'expires_at', 'failure_code', 'failure_message', 'correlation_id'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'last_attempt_at' => 'datetime', 'delivered_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function output()
    {
        return $this->belongsTo(ReportOutput::class, 'report_output_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
