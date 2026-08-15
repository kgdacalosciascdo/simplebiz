<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id', 'company_id', 'locale', 'timezone', 'date_format', 'number_format', 'paper_size',
        'accessibility', 'notification_preferences', 'display_preferences', 'preferred_branch_id',
    ];

    protected function casts(): array
    {
        return [
            'accessibility' => 'array',
            'notification_preferences' => 'array',
            'display_preferences' => 'array',
        ];
    }
}
