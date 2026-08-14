<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportAnalyticsDefinition extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'analytics_key', 'version', 'code', 'name', 'business_question', 'formula_reference', 'source_contract_keys', 'parameter_schema', 'comparison_schema', 'reconciliation_rule', 'owner_module', 'status', 'effective_from', 'effective_to', 'published_at', 'supersedes_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'source_contract_keys' => 'array', 'parameter_schema' => 'array', 'comparison_schema' => 'array', 'reconciliation_rule' => 'array', 'effective_from' => 'datetime', 'effective_to' => 'datetime', 'published_at' => 'datetime'];
    }
}
