<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportDefinition extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'definition_key', 'version', 'category_id', 'code', 'name', 'description', 'business_question', 'source_owner_module', 'source_contract_keys', 'query_adapter', 'formula_reference', 'status_basis', 'sign_convention', 'parameter_schema', 'columns_schema', 'grouping_schema', 'sorting_schema', 'totals_schema', 'security_schema', 'drilldown_schema', 'entitlement', 'sensitivity', 'output_capabilities', 'reconciliation_rule', 'effective_from', 'effective_to', 'status', 'review_status', 'reviewed_by', 'reviewed_at', 'review_notes', 'deactivated_by', 'deactivated_at', 'published_at', 'supersedes_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'source_contract_keys' => 'array', 'parameter_schema' => 'array', 'columns_schema' => 'array', 'grouping_schema' => 'array', 'sorting_schema' => 'array', 'totals_schema' => 'array', 'security_schema' => 'array', 'drilldown_schema' => 'array', 'entitlement' => 'array', 'output_capabilities' => 'array', 'reconciliation_rule' => 'array', 'effective_from' => 'datetime', 'effective_to' => 'datetime', 'reviewed_at' => 'datetime', 'deactivated_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function category()
    {
        return $this->belongsTo(ReportCategory::class);
    }

    public function parameters()
    {
        return $this->hasMany(ReportParameterDefinition::class)->orderBy('display_order');
    }

    public function columns()
    {
        return $this->hasMany(ReportColumnDefinition::class)->orderBy('display_order');
    }

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
