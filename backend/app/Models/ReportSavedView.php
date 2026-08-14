<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSavedView extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'user_id', 'report_definition_id', 'name', 'parameters', 'presentation'];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'presentation' => 'array'];
    }

    public function definition()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }
}
