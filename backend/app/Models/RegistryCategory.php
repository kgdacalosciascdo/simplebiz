<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RegistryCategory extends Model
{
    use HasUuids;

    protected $table = 'registry_categories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['display_order' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date', 'status_changed_at' => 'datetime', 'version' => 'integer'];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
