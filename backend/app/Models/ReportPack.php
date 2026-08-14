<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportPack extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'pack_key', 'version', 'name', 'description', 'status', 'parameters', 'metadata', 'created_by', 'reviewed_by', 'published_by', 'generated_at', 'reviewed_at', 'published_at', 'archived_at', 'supersedes_id', 'correlation_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'parameters' => 'array', 'metadata' => 'array', 'generated_at' => 'datetime', 'reviewed_at' => 'datetime', 'published_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(ReportPackItem::class, 'report_pack_id')->orderBy('display_order');
    }
}
