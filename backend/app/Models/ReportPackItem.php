<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportPackItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'report_pack_id', 'report_output_id', 'display_order', 'label', 'definition_key', 'definition_version'];

    protected function casts(): array
    {
        return ['display_order' => 'integer', 'definition_version' => 'integer'];
    }

    public function output()
    {
        return $this->belongsTo(ReportOutput::class, 'report_output_id');
    }
}
