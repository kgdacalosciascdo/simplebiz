<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'user_id', 'event', 'title', 'description', 'entity_type',
        'entity_id', 'correlation_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
