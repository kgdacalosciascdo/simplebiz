<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipAccessHistory extends Model
{
    public $timestamps = false;

    protected $table = 'membership_access_history';

    protected $fillable = [
        'company_id', 'user_id', 'actor_id', 'action', 'previous_state', 'new_state',
        'reason', 'source_channel', 'correlation_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['previous_state' => 'array', 'new_state' => 'array', 'created_at' => 'datetime'];
    }
}
