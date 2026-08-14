<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_definitions', function (Blueprint $table) {
            $table->string('review_status', 24)->default('approved')->after('status');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('published_at');
            $table->dateTime('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete()->after('review_notes');
            $table->dateTime('deactivated_at')->nullable()->after('deactivated_by');
        });

        Schema::table('report_outputs', function (Blueprint $table) {
            $table->dateTime('retention_expires_at')->nullable()->after('expires_at');
            $table->boolean('legal_hold')->default(false)->after('retention_expires_at');
            $table->dateTime('archived_at')->nullable()->after('legal_hold');
            $table->dateTime('purged_at')->nullable()->after('archived_at');
            $table->string('purge_reason', 180)->nullable()->after('purged_at');
            $table->index(['company_id', 'status', 'retention_expires_at']);
        });

        Schema::create('report_analytics_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('analytics_key', 120);
            $table->unsignedInteger('version')->default(1);
            $table->string('code', 120);
            $table->string('name', 180);
            $table->text('business_question');
            $table->text('formula_reference');
            $table->json('source_contract_keys');
            $table->json('parameter_schema')->nullable();
            $table->json('comparison_schema')->nullable();
            $table->json('reconciliation_rule')->nullable();
            $table->string('owner_module', 80)->default('reports');
            $table->string('status', 24)->default('published');
            $table->dateTime('effective_from')->nullable();
            $table->dateTime('effective_to')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->uuid('supersedes_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['analytics_key', 'version']);
            $table->unique(['code', 'version']);
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('report_definition_id')->constrained('report_definitions')->restrictOnDelete();
            $table->unsignedInteger('definition_version');
            $table->string('name', 180);
            $table->string('recurrence', 20);
            $table->string('timezone', 80);
            $table->string('run_time', 5);
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->json('parameters')->nullable();
            $table->string('output_format', 24)->default('pdf');
            $table->string('delivery_channel', 24)->default('in_app');
            $table->json('recipient_user_ids');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('next_run_at')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status', 'next_run_at']);
        });

        Schema::create('report_schedule_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_id')->constrained('report_schedules')->cascadeOnDelete();
            $table->string('occurrence_key', 180);
            $table->dateTime('scheduled_for');
            $table->unsignedInteger('definition_version');
            $table->json('parameters')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignUuid('report_request_id')->nullable()->constrained('report_requests')->nullOnDelete();
            $table->foreignUuid('report_output_id')->nullable()->constrained('report_outputs')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['schedule_id', 'occurrence_key']);
            $table->index(['schedule_id', 'status', 'scheduled_for']);
        });

        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('report_schedules')->nullOnDelete();
            $table->foreignUuid('occurrence_id')->nullable()->constrained('report_schedule_occurrences')->nullOnDelete();
            $table->foreignUuid('report_output_id')->constrained('report_outputs')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('channel', 24)->default('in_app');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['occurrence_id', 'recipient_user_id', 'channel']);
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('report_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('pack_key', 120);
            $table->unsignedInteger('version')->default(1);
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('draft');
            $table->json('parameters')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('generated_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->uuid('supersedes_id')->nullable()->index();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['company_id', 'pack_key', 'version']);
            $table->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('report_pack_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_pack_id')->constrained('report_packs')->cascadeOnDelete();
            $table->foreignUuid('report_output_id')->constrained('report_outputs')->restrictOnDelete();
            $table->unsignedInteger('display_order')->default(0);
            $table->string('label', 180)->nullable();
            $table->string('definition_key', 120);
            $table->unsignedInteger('definition_version');
            $table->timestamps();
            $table->unique(['report_pack_id', 'report_output_id']);
            $table->index(['report_pack_id', 'display_order']);
        });

        $this->seedPermissions();
        $this->seedCategoriesContractsAndDefinitions();
        $this->seedAnalyticsDefinitions();
    }

    private function seedPermissions(): void
    {
        $permissions = [
            ['reports.schedules.view', 'View Report Schedules'],
            ['reports.schedules.manage', 'Manage Report Schedules'],
            ['reports.schedules.run', 'Run Scheduled Reports'],
            ['reports.delivery.view', 'View Report Delivery History'],
            ['reports.delivery.retry', 'Retry Report Delivery'],
            ['reports.analytics.view', 'View Cross-Module Analytics'],
            ['reports.compare', 'Compare Governed Reports'],
            ['reports.packs.view', 'View Report Packs'],
            ['reports.packs.manage', 'Manage Report Packs'],
            ['reports.packs.publish', 'Publish Report Packs'],
            ['reports.definitions.manage', 'Manage Report Definitions'],
            ['reports.definitions.publish', 'Publish Report Definitions'],
            ['reports.retention.manage', 'Manage Report Retention'],
        ];
        foreach ($permissions as [$key, $name]) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'reports', 'description' => 'MDS-900 governed reporting completion permission.', 'updated_at' => now(), 'created_at' => now()]);
        }
        $permissionIds = DB::table('permissions')->where('module', 'reports')->pluck('id');
        $roleIds = DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    private function seedCategoriesContractsAndDefinitions(): void
    {
        $categories = [
            ['cash_accounts', 'Cash Accounts', 'Reports owned by Cash Accounts source contracts.', 65],
            ['management_analytics', 'Management & Analytics', 'Governed cross-module analytics and management reports.', 80],
        ];
        foreach ($categories as [$code, $name, $description, $order]) {
            if (! DB::table('report_categories')->where('code', $code)->exists()) {
                DB::table('report_categories')->insert(['id' => (string) Str::uuid(), 'code' => $code, 'name' => $name, 'description' => $description, 'display_order' => $order, 'status' => 'active', 'updated_at' => now(), 'created_at' => now()]);
            }
        }

        $contracts = [
            ['sales.sales_register', 'sales', 'Posted and reversed Sales documents with source-owned customer, currency, tax, discount, receivable, and status meaning.', 'sales.sales_register', 'sales.reports.view'],
            ['sales.sales_by_product', 'sales', 'Posted and reversed Sales lines grouped by source-owned Product or Service and currency.', 'sales.sales_by_product', 'sales.reports.view'],
            ['sales.receivables_aging', 'sales', 'Current positive Receivable Open Items evaluated against a governed as-of date and source-owned settlement state.', 'sales.receivables_aging', 'sales.reports.view'],
            ['cash-accounts.cash_position', 'cash-accounts', 'MDS-700 Cash Account posted, available, pending, cleared, and reconciled position by account and currency.', 'cash-accounts.cash_position', 'cash-accounts.view'],
            ['cash-accounts.cash_account_ledger', 'cash-accounts', 'MDS-700 Cash Account opening, inflow, outflow, transfer, closing, and reconciliation state by period.', 'cash-accounts.cash_account_ledger', 'cash-accounts.view'],
            ['inventory.inventory_valuation', 'inventory', 'Source-owned Inventory valuation records where the authorized MDS-600 cost source is available.', 'inventory.valuation', 'inventory.reports.view'],
            ['reports.business_performance', 'reports', 'MDS-900 management metrics over governed Sales, Expenses, Cash, Receivables, Payables, and Inventory source contracts.', 'reports.business_performance', 'reports.analytics.view'],
            ['reports.voided_reversed', 'reports', 'Cross-module control view of source-owned cancelled, voided, and reversed records.', 'reports.voided_reversed', 'reports.history.view'],
        ];
        foreach ($contracts as [$key, $module, $meaning, $adapter, $permission]) {
            if (! DB::table('report_source_contracts')->where('contract_key', $key)->where('version', 1)->exists()) {
                DB::table('report_source_contracts')->insert(['id' => (string) Str::uuid(), 'contract_key' => $key, 'version' => 1, 'source_module' => $module, 'business_meaning' => $meaning, 'query_adapter' => $adapter, 'source_permission' => $permission, 'parameter_schema' => json_encode(['from' => 'date', 'to' => 'date', 'as_of' => 'date']), 'output_schema' => json_encode(['rows' => 'array', 'source_as_of_at' => 'datetime', 'freshness_state' => 'string']), 'freshness_policy' => json_encode(['state' => 'current', 'disclose_source_as_of' => true]), 'reconciliation_rule' => json_encode(['source_owned' => true, 'currency_separation' => true]), 'drilldown' => json_encode(['preserve_company' => true, 'preserve_parameters' => true]), 'status' => 'published', 'updated_at' => now(), 'created_at' => now()]);
            }
        }

        $categoryIds = DB::table('report_categories')->pluck('id', 'code')->all();
        $contractIds = DB::table('report_source_contracts')->pluck('id', 'contract_key')->all();
        $definitions = [
            ['REP-SAL-001', 'Sales Register', 'List source-owned posted and reversed Sales documents with customer, tax, discount, and receivable context.', 'What Sales documents were recorded in the selected period?', 'sales_receivables', 'sales', 'sales.sales_register', 'sales.sales_register', 'sales.reports.view', ['date' => ['sale_number', 'date', 'customer', 'status'], 'text' => ['currency'], 'number' => ['gross_sales', 'discounts', 'tax', 'amount', 'receivable_amount']], 'amount', 'from'],
            ['REP-SAL-002', 'Sales by Product', 'Group source-owned Sales lines by Product or Service without recreating Sales meaning or margin cost.', 'Which Products or Services generated Sales in the selected period?', 'sales_receivables', 'sales', 'sales.sales_by_product', 'sales.sales_by_product', 'sales.reports.view', ['text' => ['product', 'product_code', 'currency', 'margin_state'], 'number' => ['quantity', 'gross_sales', 'discounts', 'net_sales', 'returns', 'margin']], 'net_sales', 'from'],
            ['REP-AR-001', 'Aging of Receivables', 'Show positive source-owned Receivable Open Items and their governed aging bucket at an explicit as-of date.', 'Which customer receivables remain open and when are they due?', 'sales_receivables', 'sales', 'sales.receivables_aging', 'sales.receivables_aging', 'sales.reports.view', ['date' => ['due_date'], 'text' => ['source_document_number', 'customer', 'currency', 'aging_bucket', 'status', 'due_status'], 'number' => ['open_item', 'days_past_due']], 'open_item', 'as_of'],
            ['REP-CAS-001', 'Cash Position', 'Show MDS-700 Cash Account positions by account and currency, distinguishing posted, pending, cleared, and reconciled values.', 'How much cash is held in each Cash Account at the selected as-of date?', 'cash_accounts', 'cash-accounts', 'cash-accounts.cash_position', 'cash-accounts.cash_position', 'cash-accounts.view', ['text' => ['account', 'account_code', 'account_type', 'branch', 'currency', 'status', 'reconciliation_state'], 'number' => ['posted', 'available', 'pending', 'cleared', 'reconciled']], 'posted', 'as_of'],
            ['REP-CAS-002', 'Cash Account Ledger', 'Show MDS-700 Cash Account opening, inflows, outflows, transfers, closing, and reconciliation state for a selected period.', 'How did each Cash Account balance move during the selected period?', 'cash_accounts', 'cash-accounts', 'cash-accounts.cash_account_ledger', 'cash-accounts.cash_account_ledger', 'cash-accounts.view', ['date' => ['from', 'to'], 'text' => ['account', 'account_code', 'currency', 'reconciliation_state'], 'number' => ['opening', 'inflows', 'outflows', 'transfers', 'closing', 'movement_count']], 'closing', 'from'],
            ['REP-INV-003', 'Inventory Valuation', 'Show authorized MDS-600 valuation records and disclose when cost source availability limits the result.', 'What is the source-owned inventory value at the selected as-of date?', 'inventory', 'inventory', 'inventory.inventory_valuation', 'inventory.valuation', 'inventory.reports.view', ['date' => ['effective_at'], 'text' => ['productService.name', 'currency_code', 'cost_source'], 'number' => ['quantity', 'unit_cost', 'total_cost']], 'total_cost', 'as_of'],
            ['REP-MGT-001', 'Business Performance', 'Provide a governed operational management view across source-backed Sales, Expenses, Cash, Receivables, Payables, and Inventory metrics.', 'How did the business perform across its enabled source modules?', 'management_analytics', 'reports', 'reports.business_performance', 'reports.business_performance', 'reports.analytics.view', ['text' => ['metric', 'currency', 'source_status'], 'number' => ['value', 'comparison_value', 'change']], null, 'from'],
            ['REP-CTL-001', 'Voided and Reversed Records', 'Show source-owned cancelled, voided, and reversed records for control review without editing their source workflows.', 'Which source records were voided, cancelled, or reversed?', 'audit_control', 'reports', 'reports.voided_reversed', 'reports.voided_reversed', 'reports.history.view', ['date' => ['date'], 'text' => ['module', 'record_number', 'status', 'reason', 'actor']], null, 'from'],
        ];
        foreach ($definitions as [$code, $name, $description, $question, $category, $module, $contractKey, $adapter, $permission, $columnGroups, $totalColumn, $dateParam]) {
            if (DB::table('report_definitions')->where('definition_key', $code)->where('version', 1)->exists()) {
                continue;
            }
            $definitionId = (string) Str::uuid();
            $columns = [];
            $order = 0;
            foreach ($columnGroups as $type => $keys) {
                foreach ($keys as $columnKey) {
                    $columns[] = ['key' => $columnKey, 'label' => ucwords(str_replace(['_', '.'], [' ', ' '], $columnKey)), 'type' => $type, 'sortable' => true, 'groupable' => false, 'totalable' => $columnKey === $totalColumn, 'sensitivity' => 'internal', 'order' => $order++];
                }
            }
            $parameters = [['name' => $dateParam, 'type' => 'date', 'label' => $dateParam === 'as_of' ? 'As of date' : 'From date', 'required' => false, 'default' => null, 'validation' => ['date' => true], 'order' => 0]];
            if ($dateParam === 'from') {
                $parameters[] = ['name' => 'to', 'type' => 'date', 'label' => 'To date', 'required' => false, 'default' => null, 'validation' => ['date' => true, 'after_or_equal' => 'from'], 'order' => 1];
            }
            if ($code === 'REP-MGT-001') {
                $parameters[] = ['name' => 'comparison_from', 'type' => 'date', 'label' => 'Comparison from', 'required' => false, 'default' => null, 'validation' => ['date' => true], 'order' => 2];
                $parameters[] = ['name' => 'comparison_to', 'type' => 'date', 'label' => 'Comparison to', 'required' => false, 'default' => null, 'validation' => ['date' => true], 'order' => 3];
            }
            $parameters[] = ['name' => 'page', 'type' => 'integer', 'label' => 'Page', 'required' => false, 'default' => 1, 'validation' => ['min' => 1], 'order' => 10];
            $parameters[] = ['name' => 'per_page', 'type' => 'integer', 'label' => 'Rows per page', 'required' => false, 'default' => 25, 'validation' => ['min' => 1, 'max' => 100], 'order' => 11];
            $contractId = $contractIds[$contractKey] ?? null;
            DB::table('report_definitions')->insert(['id' => $definitionId, 'definition_key' => $code, 'version' => 1, 'category_id' => $categoryIds[$category], 'code' => $code, 'name' => $name, 'description' => $description, 'business_question' => $question, 'source_owner_module' => $module, 'source_contract_keys' => json_encode([$contractKey]), 'query_adapter' => $adapter, 'formula_reference' => $code === 'REP-MGT-001' ? 'Governed source-backed metric aggregation documented by ANL-MGT-001; no user formulas.' : 'Source-owned query; no executable user formulas.', 'status_basis' => 'The originating source module controls included statuses.', 'sign_convention' => 'Amounts and quantities preserve source sign and precision; currencies remain separated.', 'parameter_schema' => json_encode($parameters), 'columns_schema' => json_encode($columns), 'grouping_schema' => json_encode(['enabled' => false, 'source_owned' => true]), 'sorting_schema' => json_encode(['allowed' => array_values(array_column($columns, 'key')), 'default' => $columns[0]['key'] ?? null]), 'totals_schema' => json_encode(['allowed' => $totalColumn ? [$totalColumn] : [], 'currency_separated' => true]), 'security_schema' => json_encode(['source_permission' => $permission, 'mask_sensitive_fields' => true]), 'drilldown_schema' => json_encode(['enabled' => true, 'preserve_context' => true, 'source_module' => $module]), 'entitlement' => json_encode(['edition' => 'SimpleBIZ Free', 'capability' => $code === 'REP-MGT-001' ? 'reports.analytics' : 'reports']), 'sensitivity' => 'internal', 'output_capabilities' => json_encode(['display' => true, 'pdf' => true, 'xlsx' => true, 'csv' => true, 'print' => true]), 'reconciliation_rule' => json_encode(['source_contract_id' => $contractId, 'source_owned' => true, 'currency_separation' => true]), 'effective_from' => now(), 'status' => 'published', 'review_status' => 'approved', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($parameters as $parameter) {
                DB::table('report_parameter_definitions')->insert(['id' => (string) Str::uuid(), 'report_definition_id' => $definitionId, 'name' => $parameter['name'], 'type' => $parameter['type'], 'label' => $parameter['label'], 'required' => $parameter['required'], 'default_value' => json_encode($parameter['default']), 'validation' => json_encode($parameter['validation']), 'display_order' => $parameter['order'], 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($columns as $column) {
                DB::table('report_column_definitions')->insert(['id' => (string) Str::uuid(), 'report_definition_id' => $definitionId, 'column_key' => $column['key'], 'label' => $column['label'], 'value_type' => $column['type'], 'visible' => true, 'sortable' => $column['sortable'], 'groupable' => $column['groupable'], 'totalable' => $column['totalable'], 'sensitivity' => $column['sensitivity'], 'source_field' => $column['key'], 'display_order' => $column['order'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        DB::table('report_definitions')->where('definition_key', 'REP-CTL-002')->where('version', 1)->update(['name' => 'Report Export and Delivery History', 'description' => 'Show governed report access, export, print, snapshot, schedule, and delivery evidence.', 'business_question' => 'Which report outputs and delivery actions were recorded?', 'updated_at' => now()]);
    }

    private function seedAnalyticsDefinitions(): void
    {
        $contracts = DB::table('report_source_contracts')->whereIn('contract_key', ['sales.sales_register', 'sales.receivables_aging', 'cash-accounts.cash_position', 'purchases.payables_aging', 'inventory.inventory_position', 'expenses.expense_register'])->pluck('id', 'contract_key')->all();
        DB::table('report_analytics_definitions')->updateOrInsert(['analytics_key' => 'ANL-MGT-001', 'version' => 1], ['id' => (string) Str::uuid(), 'code' => 'ANL-MGT-001', 'name' => 'Business Performance', 'business_question' => 'How did enabled source modules perform over the selected period?', 'formula_reference' => 'Source-backed totals are returned per source currency. Missing or unavailable sources remain unavailable and are never treated as zero. No statutory profit or loss is inferred.', 'source_contract_keys' => json_encode(array_values($contracts)), 'parameter_schema' => json_encode(['from' => 'date', 'to' => 'date', 'comparison_from' => 'date', 'comparison_to' => 'date']), 'comparison_schema' => json_encode(['period_over_period' => true, 'definition_version_required' => true]), 'reconciliation_rule' => json_encode(['each_metric_discloses_source_contract' => true, 'currency_separated' => true]), 'owner_module' => 'reports', 'status' => 'published', 'effective_from' => now(), 'published_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_pack_items');
        Schema::dropIfExists('report_packs');
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('report_schedule_occurrences');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('report_analytics_definitions');
        Schema::table('report_outputs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status', 'retention_expires_at']);
            $table->dropColumn(['retention_expires_at', 'legal_hold', 'archived_at', 'purged_at', 'purge_reason']);
        });
        Schema::table('report_definitions', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropForeign(['deactivated_by']);
            $table->dropColumn(['review_status', 'reviewed_by', 'reviewed_at', 'review_notes', 'deactivated_by', 'deactivated_at']);
        });
    }
};
