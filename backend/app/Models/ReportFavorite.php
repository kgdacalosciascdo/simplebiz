<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportFavorite extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'user_id', 'report_definition_id', 'label', 'saved_parameters', 'display_order'];

    protected function casts(): array
    {
        return ['saved_parameters' => 'array', 'display_order' => 'integer'];
    }

    public function definition()
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }
}
