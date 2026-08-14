<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportColumnDefinition extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'report_definition_id', 'column_key', 'label', 'value_type', 'format', 'visible', 'sortable', 'groupable', 'totalable', 'sensitivity', 'source_field', 'display_order'];

    protected function casts(): array
    {
        return ['visible' => 'boolean', 'sortable' => 'boolean', 'groupable' => 'boolean', 'totalable' => 'boolean', 'display_order' => 'integer'];
    }

    public function definition()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }
}
