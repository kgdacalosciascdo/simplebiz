<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistryHistory extends Model
{
    public $timestamps = false;

    protected $table = 'registry_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'created_at' => 'datetime'];
    }
}
