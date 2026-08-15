<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $contractKey = 'sales.sales_returns_adjustments';
        $contractId = DB::table('report_source_contracts')->where('contract_key', $contractKey)->where('version', 1)->value('id');
        if (! $contractId) {
            $contractId = (string) Str::uuid();
            DB::table('report_source_contracts')->insert(['id' => $contractId, 'contract_key' => $contractKey, 'version' => 1, 'source_module' => 'sales', 'business_meaning' => 'Posted and reversed Sales Returns and Sales Adjustments, preserving source Sale, customer, currency, lifecycle, and receivable-effect meaning.', 'query_adapter' => $contractKey, 'source_permission' => 'sales.reports.view', 'parameter_schema' => json_encode(['from' => 'date', 'to' => 'date']), 'output_schema' => json_encode(['rows' => 'array', 'source_as_of_at' => 'datetime', 'freshness_state' => 'string']), 'freshness_policy' => json_encode(['state' => 'current', 'disclose_source_as_of' => true]), 'reconciliation_rule' => json_encode(['source_owned' => true, 'currency_separation' => true, 'linked_sale_required' => true]), 'drilldown' => json_encode(['preserve_company' => true, 'preserve_parameters' => true]), 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        }

        if (DB::table('report_definitions')->where('definition_key', 'REP-SAL-003')->where('version', 1)->exists()) {
            return;
        }

        $definitionId = (string) Str::uuid();
        $categoryId = DB::table('report_categories')->where('code', 'sales_receivables')->value('id');
        $columns = [
            ['key' => 'document_number', 'label' => 'Document Number', 'type' => 'text'],
            ['key' => 'source_type', 'label' => 'Source Type', 'type' => 'text'],
            ['key' => 'sale_number', 'label' => 'Sale Number', 'type' => 'text'],
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'customer', 'label' => 'Customer', 'type' => 'text'],
            ['key' => 'currency', 'label' => 'Currency', 'type' => 'text'],
            ['key' => 'adjustment_type', 'label' => 'Adjustment Type', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'number'],
            ['key' => 'receivable_effect', 'label' => 'Receivable Effect', 'type' => 'number'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
        ];
        $parameters = [['name' => 'from', 'type' => 'date', 'label' => 'From date', 'required' => false, 'default' => null, 'validation' => ['date' => true], 'order' => 0], ['name' => 'to', 'type' => 'date', 'label' => 'To date', 'required' => false, 'default' => null, 'validation' => ['date' => true, 'after_or_equal' => 'from'], 'order' => 1], ['name' => 'page', 'type' => 'integer', 'label' => 'Page', 'required' => false, 'default' => 1, 'validation' => ['min' => 1], 'order' => 10], ['name' => 'per_page', 'type' => 'integer', 'label' => 'Rows per page', 'required' => false, 'default' => 25, 'validation' => ['min' => 1, 'max' => 100], 'order' => 11]];
        DB::table('report_definitions')->insert(['id' => $definitionId, 'definition_key' => 'REP-SAL-003', 'version' => 1, 'category_id' => $categoryId, 'code' => 'REP-SAL-003', 'name' => 'Sales Returns & Adjustments', 'description' => 'List source-owned posted and reversed Sales Returns and Sales Adjustments linked to their originating Sale.', 'business_question' => 'Which Sales corrections affected the customer account during the selected period?', 'source_owner_module' => 'sales', 'source_contract_keys' => json_encode([$contractKey]), 'query_adapter' => $contractKey, 'formula_reference' => 'Source-owned query; no executable user formulas.', 'status_basis' => 'The originating Sales correction lifecycle controls included statuses.', 'sign_convention' => 'Returns and credit adjustments reduce receivables; debit adjustments increase receivables; currencies remain separated.', 'parameter_schema' => json_encode($parameters), 'columns_schema' => json_encode(array_map(fn (array $column, int $order) => $column + ['sortable' => true, 'groupable' => false, 'totalable' => $column['key'] === 'amount', 'sensitivity' => 'internal', 'order' => $order], $columns, array_keys($columns))), 'grouping_schema' => json_encode(['enabled' => false, 'source_owned' => true]), 'sorting_schema' => json_encode(['allowed' => array_column($columns, 'key'), 'default' => 'date']), 'totals_schema' => json_encode(['allowed' => ['amount', 'receivable_effect'], 'currency_separated' => true]), 'security_schema' => json_encode(['source_permission' => 'sales.reports.view', 'mask_sensitive_fields' => true]), 'drilldown_schema' => json_encode(['enabled' => true, 'preserve_context' => true, 'source_module' => 'sales']), 'entitlement' => json_encode(['edition' => 'SimpleBIZ Free', 'capability' => 'reports']), 'sensitivity' => 'internal', 'output_capabilities' => json_encode(['display' => true, 'pdf' => true, 'xlsx' => true, 'csv' => true, 'print' => true]), 'reconciliation_rule' => json_encode(['source_contract_id' => $contractId, 'source_owned' => true, 'currency_separation' => true]), 'effective_from' => now(), 'status' => 'published', 'review_status' => 'approved', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($parameters as $parameter) {
            DB::table('report_parameter_definitions')->insert(['id' => (string) Str::uuid(), 'report_definition_id' => $definitionId, 'name' => $parameter['name'], 'type' => $parameter['type'], 'label' => $parameter['label'], 'required' => $parameter['required'], 'default_value' => json_encode($parameter['default']), 'validation' => json_encode($parameter['validation']), 'display_order' => $parameter['order'], 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($columns as $order => $column) {
            DB::table('report_column_definitions')->insert(['id' => (string) Str::uuid(), 'report_definition_id' => $definitionId, 'column_key' => $column['key'], 'label' => $column['label'], 'value_type' => $column['type'], 'visible' => true, 'sortable' => true, 'groupable' => false, 'totalable' => in_array($column['key'], ['amount', 'receivable_effect'], true), 'sensitivity' => 'internal', 'source_field' => $column['key'], 'display_order' => $order, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $definitionId = DB::table('report_definitions')->where('definition_key', 'REP-SAL-003')->where('version', 1)->value('id');
        if ($definitionId) {
            DB::table('report_parameter_definitions')->where('report_definition_id', $definitionId)->delete();
            DB::table('report_column_definitions')->where('report_definition_id', $definitionId)->delete();
            DB::table('report_definitions')->where('id', $definitionId)->delete();
        }
        DB::table('report_source_contracts')->where('contract_key', 'sales.sales_returns_adjustments')->where('version', 1)->delete();
    }
};
