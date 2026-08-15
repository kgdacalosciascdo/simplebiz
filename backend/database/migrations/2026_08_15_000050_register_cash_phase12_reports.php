<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $categoryId = DB::table('report_categories')->where('code', 'cash_accounts')->value('id');
        if (! $categoryId) {
            return;
        }

        $contracts = [
            ['cash-accounts.cash_movement_history', 'cash-accounts.cash_movement_history', 'MDS-700 posted and pending Cash Movement source history with status, source trace, and currency.', 'cash-accounts.movements.history'],
            ['cash-accounts.cash_transfer_history', 'cash-accounts.cash_transfer_history', 'MDS-700 two-leg internal transfer history, preserving source and destination identity without inflating company cash totals.', 'cash-accounts.transfers.view'],
            ['cash-accounts.cash_count', 'cash-accounts.cash_count', 'MDS-700 Cash Count expected, actual, variance, disposition, and custody evidence projection.', 'cash-accounts.cash-counts.reports.view'],
            ['cash-accounts.cash_reconciliation', 'cash-accounts.cash_reconciliation', 'MDS-700 reconciliation population, matching, outstanding, difference, completion, and lock projection.', 'cash-accounts.reconciliations.view'],
            ['cash-accounts.cash_exceptions', 'cash-accounts.cash_exceptions', 'MDS-700 Cash Account needs-attention projection for negative balances, pending work, unreconciled movements, count variance, and open reconciliation.', 'cash-accounts.view'],
        ];
        foreach ($contracts as [$key, $adapter, $meaning, $permission]) {
            DB::table('report_source_contracts')->updateOrInsert(['contract_key' => $key, 'version' => 1], ['id' => (string) Str::uuid(), 'source_module' => 'cash-accounts', 'business_meaning' => $meaning, 'query_adapter' => $adapter, 'source_permission' => $permission, 'parameter_schema' => json_encode(['from' => 'date', 'to' => 'date', 'as_of' => 'date']), 'output_schema' => json_encode(['rows' => 'array', 'source_as_of_at' => 'datetime', 'freshness_state' => 'string', 'currency_context' => 'string']), 'freshness_policy' => json_encode(['state' => 'current', 'disclose_source_as_of' => true]), 'reconciliation_rule' => json_encode(['source_owned' => true, 'currency_separation' => true]), 'drilldown' => json_encode(['preserve_company' => true, 'preserve_parameters' => true]), 'status' => 'published', 'updated_at' => now(), 'created_at' => now()]);
        }

        $definitions = [
            ['REP-CAS-003', 'Cash Movement History', 'Show Cash Movement records with their source, status, clearing, reconciliation, and currency context.', 'Which Cash Movements changed or are pending in the selected period?', 'cash-accounts.cash_movement_history', ['id', 'business_date', 'account', 'account_code', 'currency', 'direction', 'amount', 'movement_status', 'clearing_status', 'reconciliation_status', 'source_event_type', 'source_reference']],
            ['REP-CAS-004', 'Cash Transfer History', 'Show two-leg internal Cash Transfers and their completion status without treating transfers as company inflows or outflows.', 'Which internal Cash Transfers were prepared or posted in the selected period?', 'cash-accounts.cash_transfer_history', ['id', 'document_number', 'business_date', 'source_account', 'source_account_code', 'destination_account', 'destination_account_code', 'currency', 'amount', 'status', 'source_movement_id', 'destination_movement_id']],
            ['REP-CAS-005', 'Cash Count Report', 'Show Cash Count expected, actual, variance, and disposition state by Cash Account and currency.', 'Which Cash Counts and variances require review or evidence?', 'cash-accounts.cash_count', ['id', 'count_number', 'count_date', 'account', 'account_code', 'currency', 'status', 'expected_amount', 'actual_amount', 'variance_amount', 'variance_classification', 'variance_status']],
            ['REP-CAS-006', 'Cash Reconciliation Report', 'Show Cash Account statement and internal populations, outstanding amounts, differences, lifecycle status, and lock state.', 'Which Cash Account reconciliations remain open or were completed in the selected period?', 'cash-accounts.cash_reconciliation', ['id', 'reconciliation_number', 'account', 'account_code', 'currency', 'period_start', 'period_end', 'status', 'matched_statement_amount', 'matched_movement_amount', 'outstanding_statement_amount', 'outstanding_movement_amount', 'difference_amount', 'locked_at']],
            ['REP-CAS-007', 'Cash Account Exceptions', 'Show server-owned MDS-700 Cash Account needs-attention records and source drilldown identifiers.', 'What Cash Account conditions need review now?', 'cash-accounts.cash_exceptions', ['code', 'severity', 'title', 'count', 'source_owner', 'source_id']],
        ];
        $contractIds = DB::table('report_source_contracts')->whereIn('contract_key', collect($definitions)->pluck(4))->pluck('id', 'contract_key');
        foreach ($definitions as [$code, $name, $description, $question, $contractKey, $columnKeys]) {
            if (DB::table('report_definitions')->where('definition_key', $code)->where('version', 1)->exists()) {
                continue;
            }
            $definitionId = (string) Str::uuid();
            $columns = collect($columnKeys)->values()->map(fn ($key, $order) => ['key' => $key, 'label' => ucwords(str_replace('_', ' ', $key)), 'type' => str_contains($key, 'amount') || $key === 'count' ? 'number' : 'text', 'sortable' => true, 'groupable' => false, 'totalable' => in_array($key, ['amount', 'count', 'variance_amount', 'difference_amount'], true), 'sensitivity' => 'internal', 'order' => $order])->all();
            $parameters = [['name' => 'from', 'type' => 'date', 'label' => 'From date', 'required' => false, 'default' => null, 'validation' => ['date' => true], 'order' => 0], ['name' => 'to', 'type' => 'date', 'label' => 'To date', 'required' => false, 'default' => null, 'validation' => ['date' => true, 'after_or_equal' => 'from'], 'order' => 1], ['name' => 'page', 'type' => 'integer', 'label' => 'Page', 'required' => false, 'default' => 1, 'validation' => ['min' => 1], 'order' => 10], ['name' => 'per_page', 'type' => 'integer', 'label' => 'Rows per page', 'required' => false, 'default' => 25, 'validation' => ['min' => 1, 'max' => 100], 'order' => 11]];
            DB::table('report_definitions')->insert(['id' => $definitionId, 'definition_key' => $code, 'version' => 1, 'category_id' => $categoryId, 'code' => $code, 'name' => $name, 'description' => $description, 'business_question' => $question, 'source_owner_module' => 'cash-accounts', 'source_contract_keys' => json_encode([$contractKey]), 'query_adapter' => $contractKey, 'formula_reference' => 'Source-owned MDS-700 query; no user formulas.', 'status_basis' => 'The Cash Accounts source owns included statuses and lifecycle meaning.', 'sign_convention' => 'Source amounts and currencies are preserved; internal transfers remain separate from company inflows and outflows.', 'parameter_schema' => json_encode($parameters), 'columns_schema' => json_encode($columns), 'grouping_schema' => json_encode(['enabled' => false, 'source_owned' => true]), 'sorting_schema' => json_encode(['allowed' => $columnKeys, 'default' => $columnKeys[0] ?? null]), 'totals_schema' => json_encode(['allowed' => [], 'currency_separated' => true]), 'security_schema' => json_encode(['source_permission' => 'cash-accounts.view', 'mask_sensitive_fields' => true]), 'drilldown_schema' => json_encode(['enabled' => true, 'preserve_context' => true, 'source_module' => 'cash-accounts']), 'entitlement' => json_encode(['edition' => 'SimpleBIZ Free', 'capability' => 'cash-accounts.reports']), 'sensitivity' => 'internal', 'output_capabilities' => json_encode(['display' => true, 'pdf' => true, 'xlsx' => true, 'csv' => true, 'print' => true]), 'reconciliation_rule' => json_encode(['source_contract_id' => $contractIds[$contractKey] ?? null, 'source_owned' => true, 'currency_separation' => true]), 'effective_from' => now(), 'status' => 'published', 'review_status' => 'approved', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($parameters as $parameter) {
                DB::table('report_parameter_definitions')->insert(['id' => (string) Str::uuid(), 'report_definition_id' => $definitionId, 'name' => $parameter['name'], 'type' => $parameter['type'], 'label' => $parameter['label'], 'required' => $parameter['required'], 'default_value' => json_encode($parameter['default']), 'validation' => json_encode($parameter['validation']), 'display_order' => $parameter['order'], 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($columns as $column) {
                DB::table('report_column_definitions')->insert(['id' => (string) Str::uuid(), 'report_definition_id' => $definitionId, 'column_key' => $column['key'], 'label' => $column['label'], 'value_type' => $column['type'], 'visible' => true, 'sortable' => $column['sortable'], 'groupable' => $column['groupable'], 'totalable' => $column['totalable'], 'sensitivity' => $column['sensitivity'], 'source_field' => $column['key'], 'display_order' => $column['order'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $definitionIds = DB::table('report_definitions')->whereIn('definition_key', ['REP-CAS-003', 'REP-CAS-004', 'REP-CAS-005', 'REP-CAS-006', 'REP-CAS-007'])->pluck('id');
        DB::table('report_parameter_definitions')->whereIn('report_definition_id', $definitionIds)->delete();
        DB::table('report_column_definitions')->whereIn('report_definition_id', $definitionIds)->delete();
        DB::table('report_definitions')->whereIn('id', $definitionIds)->delete();
        DB::table('report_source_contracts')->whereIn('contract_key', ['cash-accounts.cash_movement_history', 'cash-accounts.cash_transfer_history', 'cash-accounts.cash_count', 'cash-accounts.cash_reconciliation', 'cash-accounts.cash_exceptions'])->delete();
    }
};
