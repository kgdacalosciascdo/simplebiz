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
        Schema::create('report_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });

        Schema::create('report_source_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('contract_key', 120);
            $table->unsignedInteger('version')->default(1);
            $table->string('source_module', 80);
            $table->text('business_meaning');
            $table->string('query_adapter', 160);
            $table->string('source_permission', 160)->nullable();
            $table->json('parameter_schema')->nullable();
            $table->json('output_schema')->nullable();
            $table->json('freshness_policy')->nullable();
            $table->json('reconciliation_rule')->nullable();
            $table->json('drilldown')->nullable();
            $table->string('status', 24)->default('published');
            $table->timestamps();
            $table->unique(['contract_key', 'version']);
            $table->index(['source_module', 'status']);
        });

        Schema::create('report_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('definition_key', 120);
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('category_id')->constrained('report_categories')->restrictOnDelete();
            $table->string('code', 120);
            $table->string('name', 180);
            $table->text('description');
            $table->text('business_question')->nullable();
            $table->string('source_owner_module', 80);
            $table->json('source_contract_keys');
            $table->string('query_adapter', 160);
            $table->text('formula_reference')->nullable();
            $table->string('status_basis', 160)->nullable();
            $table->string('sign_convention', 160)->nullable();
            $table->json('parameter_schema')->nullable();
            $table->json('columns_schema')->nullable();
            $table->json('grouping_schema')->nullable();
            $table->json('sorting_schema')->nullable();
            $table->json('totals_schema')->nullable();
            $table->json('security_schema')->nullable();
            $table->json('drilldown_schema')->nullable();
            $table->json('entitlement')->nullable();
            $table->string('sensitivity', 24)->default('internal');
            $table->json('output_capabilities')->nullable();
            $table->json('reconciliation_rule')->nullable();
            $table->dateTime('effective_from')->nullable();
            $table->dateTime('effective_to')->nullable();
            $table->string('status', 24)->default('published');
            $table->dateTime('published_at')->nullable();
            // Historical definition links are deliberately nullable metadata; keeping this as
            // an indexed UUID avoids coupling publication history to a self-referencing FK.
            $table->uuid('supersedes_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['definition_key', 'version']);
            $table->unique(['code', 'version']);
            $table->index(['category_id', 'status']);
            $table->index(['source_owner_module', 'status']);
        });

        Schema::create('report_parameter_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_definition_id')->constrained('report_definitions')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 40);
            $table->string('label', 160);
            $table->boolean('required')->default(false);
            $table->json('default_value')->nullable();
            $table->string('valid_source', 160)->nullable();
            $table->json('dependencies')->nullable();
            $table->boolean('multi_select')->default(false);
            $table->string('authorization', 160)->nullable();
            $table->json('validation')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->unique(['report_definition_id', 'name']);
        });

        Schema::create('report_column_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_definition_id')->constrained('report_definitions')->cascadeOnDelete();
            $table->string('column_key', 100);
            $table->string('label', 160);
            $table->string('value_type', 40)->default('text');
            $table->string('format', 80)->nullable();
            $table->boolean('visible')->default(true);
            $table->boolean('sortable')->default(false);
            $table->boolean('groupable')->default(false);
            $table->boolean('totalable')->default(false);
            $table->string('sensitivity', 24)->default('internal');
            $table->string('source_field', 160)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->unique(['report_definition_id', 'column_key']);
        });

        Schema::create('report_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('report_definition_id')->constrained('report_definitions')->restrictOnDelete();
            $table->unsignedInteger('definition_version');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('parameters')->nullable();
            $table->json('context')->nullable();
            $table->string('output_type', 24)->default('display');
            $table->string('status', 24)->default('requested');
            $table->dateTime('source_as_of_at')->nullable();
            $table->string('freshness_state', 24)->nullable();
            $table->date('as_of_date')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('idempotency_identity', 180)->nullable();
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('validated_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedInteger('result_count')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'idempotency_identity']);
            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['company_id', 'report_definition_id', 'created_at']);
        });

        Schema::create('report_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('report_request_id')->constrained('report_requests')->cascadeOnDelete();
            $table->foreignUuid('report_definition_id')->constrained('report_definitions')->restrictOnDelete();
            $table->unsignedInteger('definition_version');
            $table->string('format', 24)->default('display');
            $table->string('status', 24)->default('available');
            $table->string('storage_disk', 80)->nullable();
            $table->string('storage_path', 255)->nullable();
            $table->string('content_type', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('result_data')->nullable();
            $table->json('result_meta')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('downloaded_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'report_request_id']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('report_output_id')->constrained('report_outputs')->restrictOnDelete();
            $table->foreignUuid('report_request_id')->constrained('report_requests')->restrictOnDelete();
            $table->foreignUuid('report_definition_id')->constrained('report_definitions')->restrictOnDelete();
            $table->unsignedInteger('definition_version');
            $table->string('status', 24)->default('archived');
            $table->string('title', 220);
            $table->json('parameters')->nullable();
            $table->json('context')->nullable();
            $table->date('as_of_date')->nullable();
            $table->dateTime('source_as_of_at')->nullable();
            $table->string('freshness_state', 24)->nullable();
            $table->json('result_data');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'report_definition_id']);
        });

        Schema::create('report_favorites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('report_definition_id')->constrained('report_definitions')->restrictOnDelete();
            $table->string('label', 180)->nullable();
            $table->json('saved_parameters')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'report_definition_id']);
        });

        Schema::create('report_saved_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('report_definition_id')->constrained('report_definitions')->restrictOnDelete();
            $table->string('name', 180);
            $table->json('parameters')->nullable();
            $table->json('presentation')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'report_definition_id', 'name']);
        });

        Schema::create('report_access_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('report_request_id')->nullable()->constrained('report_requests')->nullOnDelete();
            $table->foreignUuid('report_output_id')->nullable()->constrained('report_outputs')->nullOnDelete();
            $table->foreignUuid('report_definition_id')->nullable()->constrained('report_definitions')->nullOnDelete();
            $table->unsignedInteger('definition_version')->nullable();
            $table->string('action', 80);
            $table->string('format', 24)->nullable();
            $table->string('parameter_hash', 64)->nullable();
            $table->json('source_modules')->nullable();
            $table->string('result_state', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'action', 'created_at']);
            $table->index(['company_id', 'user_id', 'created_at']);
        });

        $permissions = [
            ['reports.view', 'View Reports Catalog', 'reports'],
            ['reports.generate', 'Generate Reports', 'reports'],
            ['reports.display', 'Display Reports', 'reports'],
            ['reports.export', 'Export Reports', 'reports'],
            ['reports.print', 'Print Reports', 'reports'],
            ['reports.drilldown', 'Drill Down from Reports', 'reports'],
            ['reports.favorite', 'Manage Report Favorites', 'reports'],
            ['reports.saved-views', 'Manage Report Saved Views', 'reports'],
            ['reports.history.view', 'View Report History', 'reports'],
            ['reports.snapshot.create', 'Create Report Snapshots', 'reports'],
            ['reports.snapshot.view', 'View Report Snapshots', 'reports'],
            ['reports.catalog.detail', 'View Report Definition Details', 'reports'],
        ];
        foreach ($permissions as [$key, $name, $module]) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => $module, 'description' => 'MDS-900 governed reporting permission.', 'updated_at' => now(), 'created_at' => now()]);
        }
        $permissionIds = DB::table('permissions')->where('module', 'reports')->pluck('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $categories = [
            ['sales_receivables', 'Sales & Receivables', 'Reports owned by Sales & Receivables source contracts.', 10],
            ['collections_receipts', 'Collections & Receipts', 'Reports owned by Collections & Receipts source contracts.', 20],
            ['purchases_payables', 'Purchases & Payables', 'Reports owned by Purchases & Payables source contracts.', 30],
            ['payments_disbursements', 'Payments & Disbursements', 'Reports owned by Payments & Disbursements source contracts.', 40],
            ['inventory', 'Inventory', 'Reports owned by Inventory source contracts.', 50],
            ['expenses', 'Expenses', 'Reports owned by Expenses source contracts.', 60],
            ['audit_control', 'Audit & Control', 'Governed report access and control history.', 70],
        ];
        foreach ($categories as [$code, $name, $description, $order]) {
            DB::table('report_categories')->insert(['id' => (string) Str::uuid(), 'code' => $code, 'name' => $name, 'description' => $description, 'display_order' => $order, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }

        $categoryIds = DB::table('report_categories')->pluck('id', 'code')->all();
        $contracts = [
            ['collections.receipt_register', 'collections', 'Posted and reversed customer receipts with their governed receipt/tender context.', 'collections.receipt_register', 'collections.reports.view'],
            ['collections.collections_summary', 'collections', 'Posted customer receipt tender summary grouped by payment method.', 'collections.payment_method_summary', 'collections.reports.view'],
            ['purchases.purchase_register', 'purchases', 'Purchase Orders and purchase transactions owned by Purchases & Payables.', 'purchases.purchase-register', 'purchases.reports.view'],
            ['purchases.payables_aging', 'purchases', 'Open supplier payable items and their due-date aging.', 'purchases.payables-aging', 'purchases.reports.view'],
            ['payments.payment_register', 'payments', 'Payment requests and disbursement register owned by Payments & Disbursements.', 'payments.payment-register', 'payments.reports.view'],
            ['inventory.inventory_position', 'inventory', 'Current inventory position owned by Inventory.', 'inventory.inventory_position', 'inventory.reports.view'],
            ['inventory.stock_card', 'inventory', 'Inventory stock movement history owned by Inventory.', 'inventory.stock_card', 'inventory.reports.view'],
            ['expenses.expense_register', 'expenses', 'Expense records and their source-owned classification/evidence context.', 'expenses.expense_register', 'expenses.reports.view'],
            ['expenses.expense_analysis', 'expenses', 'Expense analysis grouped by the source-owned expense category query.', 'expenses.by-category', 'expenses.reports.view'],
            ['reports.export_history', 'reports', 'MDS-900 report generation, export, print, and snapshot access history.', 'reports.export_history', 'reports.history.view'],
        ];
        foreach ($contracts as [$key, $module, $meaning, $adapter, $permission]) {
            DB::table('report_source_contracts')->insert(['id' => (string) Str::uuid(), 'contract_key' => $key, 'version' => 1, 'source_module' => $module, 'business_meaning' => $meaning, 'query_adapter' => $adapter, 'source_permission' => $permission, 'parameter_schema' => json_encode(['from' => 'date', 'to' => 'date', 'as_of' => 'date']), 'output_schema' => json_encode(['rows' => 'array', 'source_as_of_at' => 'datetime', 'freshness_state' => 'string']), 'freshness_policy' => json_encode(['state' => 'current', 'disclose_source_as_of' => true]), 'reconciliation_rule' => json_encode(['source_owned' => true, 'currency_separation' => true]), 'drilldown' => json_encode(['preserve_company' => true, 'preserve_parameters' => true]), 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        }
        $contractIds = DB::table('report_source_contracts')->pluck('id', 'contract_key')->all();

        $definitions = [
            ['REP-COL-001', 'Receipt Register', 'List posted and reversed customer receipts without redefining collection meaning.', 'Which customer receipts were recorded in the selected period?', 'collections_receipts', 'collections', 'collections.receipt_register', 'collections.receipt_register', 'collections.reports.view', ['date' => ['id', 'receipt_number', 'date', 'customer', 'type', 'amount', 'status']], 'amount'],
            ['REP-COL-002', 'Collections Summary', 'Summarize posted receipt tenders by payment method using the source-owned query.', 'How were posted customer collections received?', 'collections_receipts', 'collections', 'collections.collections_summary', 'collections.payment_method_summary', 'collections.reports.view', ['text' => ['payment_method', 'count'], 'number' => ['amount']], 'amount'],
            ['REP-PUR-001', 'Purchase Register', 'List source-owned purchase transactions and their supplier context.', 'What purchases were recorded in the selected period?', 'purchases_payables', 'purchases', 'purchases.purchase_register', 'purchases.purchase-register', 'purchases.reports.view', ['date' => ['purchase_date', 'order_number', 'supplier', 'status'], 'number' => ['total']], 'total'],
            ['REP-AP-001', 'Aging of Payables', 'Show open supplier payable items and due-date context.', 'Which supplier obligations remain open and when are they due?', 'purchases_payables', 'purchases', 'purchases.payables_aging', 'purchases.payables-aging', 'purchases.reports.view', ['date' => ['due_date', 'document_number', 'supplier', 'status'], 'number' => ['remaining_amount']], 'remaining_amount'],
            ['REP-PAY-001', 'Payment Register', 'List payment requests and disbursement records through the payment source service.', 'What payments and disbursements were recorded?', 'payments_disbursements', 'payments', 'payments.payment_register', 'payments.payment-register', 'payments.reports.view', ['date' => ['date', 'payment_number', 'payee', 'status'], 'number' => ['amount']], 'amount'],
            ['REP-INV-001', 'Inventory Position', 'Show the source-owned current inventory position.', 'What inventory is currently available?', 'inventory', 'inventory', 'inventory.inventory_position', 'inventory.inventory_position', 'inventory.reports.view', ['text' => ['product', 'warehouse', 'status'], 'number' => ['quantity', 'available_quantity']], 'available_quantity'],
            ['REP-INV-002', 'Stock Card', 'Show source-owned stock movement history.', 'How did stock quantities move over the selected period?', 'inventory', 'inventory', 'inventory.stock_card', 'inventory.stock_card', 'inventory.reports.view', ['date' => ['date', 'movement_number', 'product', 'movement_type'], 'number' => ['quantity']], 'quantity'],
            ['REP-EXP-001', 'Expense Register', 'List source-owned expenses with classification and settlement context.', 'What expenses were recorded in the selected period?', 'expenses', 'expenses', 'expenses.expense_register', 'expenses.expense_register', 'expenses.reports.view', ['date' => ['business_date', 'expense_number', 'description', 'status'], 'number' => ['total']], 'total'],
            ['REP-EXP-002', 'Expense Analysis', 'Use the Expenses source query to analyze expenses by category.', 'How are expenses distributed across source-owned categories?', 'expenses', 'expenses', 'expenses.expense_analysis', 'expenses.by-category', 'expenses.reports.view', ['text' => ['category', 'currency'], 'number' => ['count', 'total']], 'total'],
            ['REP-CTL-002', 'Report Access History', 'Show report generation, export, print, and snapshot history for the current company.', 'Which governed reporting outputs were accessed?', 'audit_control', 'reports', 'reports.export_history', 'reports.export_history', 'reports.history.view', ['date' => ['created_at', 'action', 'format', 'result_state'], 'text' => ['definition_key', 'user']], null],
        ];
        foreach ($definitions as [$code, $name, $description, $question, $category, $module, $contractKey, $adapter, $permission, $columnGroups, $totalColumn]) {
            $definitionId = (string) Str::uuid();
            $columns = [];
            $order = 0;
            foreach ($columnGroups as $type => $keys) {
                foreach ($keys as $columnKey) {
                    $columns[] = ['key' => $columnKey, 'label' => ucwords(str_replace('_', ' ', $columnKey)), 'type' => $type, 'sortable' => true, 'groupable' => false, 'totalable' => $columnKey === $totalColumn, 'sensitivity' => 'internal', 'order' => $order++];
                }
            }
            $dateParam = in_array($code, ['REP-INV-001', 'REP-CTL-002'], true) ? 'as_of' : 'from';
            $parameters = [['name' => $dateParam, 'type' => 'date', 'label' => $dateParam === 'as_of' ? 'As of date' : 'From date', 'required' => false, 'default' => null, 'validation' => ['date' => true], 'order' => 0]];
            if ($dateParam === 'from') {
                $parameters[] = ['name' => 'to', 'type' => 'date', 'label' => 'To date', 'required' => false, 'default' => null, 'validation' => ['date' => true, 'after_or_equal' => 'from'], 'order' => 1];
            }
            $parameters[] = ['name' => 'page', 'type' => 'integer', 'label' => 'Page', 'required' => false, 'default' => 1, 'validation' => ['min' => 1], 'order' => 10];
            $parameters[] = ['name' => 'per_page', 'type' => 'integer', 'label' => 'Rows per page', 'required' => false, 'default' => 25, 'validation' => ['min' => 1, 'max' => 100], 'order' => 11];
            $sourceContract = $contractIds[$contractKey];
            DB::table('report_definitions')->insert(['id' => $definitionId, 'definition_key' => $code, 'version' => 1, 'category_id' => $categoryIds[$category], 'code' => $code, 'name' => $name, 'description' => $description, 'business_question' => $question, 'source_owner_module' => $module, 'source_contract_keys' => json_encode([$contractKey]), 'query_adapter' => $adapter, 'formula_reference' => 'Source-owned query; no executable user formulas.', 'status_basis' => 'The originating source module controls included statuses.', 'sign_convention' => 'Amounts and quantities preserve source sign and precision; currencies remain separated.', 'parameter_schema' => json_encode($parameters), 'columns_schema' => json_encode($columns), 'grouping_schema' => json_encode(['enabled' => false, 'source_owned' => true]), 'sorting_schema' => json_encode(['allowed' => array_values(array_column($columns, 'key')), 'default' => $columns[0]['key'] ?? null]), 'totals_schema' => json_encode(['allowed' => $totalColumn ? [$totalColumn] : [], 'currency_separated' => true]), 'security_schema' => json_encode(['source_permission' => $permission, 'mask_sensitive_fields' => true]), 'drilldown_schema' => json_encode(['enabled' => true, 'preserve_context' => true, 'source_module' => $module]), 'entitlement' => json_encode(['edition' => 'SimpleBIZ Free']), 'sensitivity' => 'internal', 'output_capabilities' => json_encode(['display' => true, 'pdf' => true, 'xlsx' => true, 'csv' => true, 'print' => true]), 'reconciliation_rule' => json_encode(['source_contract_id' => $sourceContract, 'source_owned' => true]), 'effective_from' => now(), 'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
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
        Schema::dropIfExists('report_access_audits');
        Schema::dropIfExists('report_saved_views');
        Schema::dropIfExists('report_favorites');
        Schema::dropIfExists('report_snapshots');
        Schema::dropIfExists('report_outputs');
        Schema::dropIfExists('report_requests');
        Schema::dropIfExists('report_column_definitions');
        Schema::dropIfExists('report_parameter_definitions');
        Schema::dropIfExists('report_definitions');
        Schema::dropIfExists('report_source_contracts');
        Schema::dropIfExists('report_categories');
    }
};
