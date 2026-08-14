<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportParameterDefinition extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'report_definition_id', 'name', 'type', 'label', 'required', 'default_value', 'valid_source', 'dependencies', 'multi_select', 'authorization', 'validation', 'display_order'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'default_value' => 'array', 'dependencies' => 'array', 'multi_select' => 'boolean', 'validation' => 'array', 'display_order' => 'integer'];
    }

    public function definition()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }
}
