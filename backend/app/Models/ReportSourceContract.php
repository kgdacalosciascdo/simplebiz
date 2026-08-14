<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSourceContract extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'contract_key', 'version', 'source_module', 'business_meaning', 'query_adapter', 'source_permission', 'parameter_schema', 'output_schema', 'freshness_policy', 'reconciliation_rule', 'drilldown', 'status'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'parameter_schema' => 'array', 'output_schema' => 'array', 'freshness_policy' => 'array', 'reconciliation_rule' => 'array', 'drilldown' => 'array'];
    }
}
