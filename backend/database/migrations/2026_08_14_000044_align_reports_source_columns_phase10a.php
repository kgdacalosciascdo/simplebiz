<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            'REP-COL-001' => [['key' => 'receipt_number', 'label' => 'Receipt number', 'type' => 'text'], ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'customer', 'label' => 'Customer', 'type' => 'text'], ['key' => 'type', 'label' => 'Type', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'totalable' => true], ['key' => 'status', 'label' => 'Status', 'type' => 'text']],
            'REP-COL-002' => [['key' => 'payment_method', 'label' => 'Payment method', 'type' => 'text'], ['key' => 'count', 'label' => 'Receipt count', 'type' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'totalable' => true]],
            'REP-AP-001' => [['key' => 'source_document_number', 'label' => 'Source document', 'type' => 'text'], ['key' => 'supplier.display_name', 'label' => 'Supplier', 'type' => 'text'], ['key' => 'due_date', 'label' => 'Due date', 'type' => 'date'], ['key' => 'settlement_status', 'label' => 'Settlement status', 'type' => 'text'], ['key' => 'due_status', 'label' => 'Due status', 'type' => 'text'], ['key' => 'remaining_amount', 'label' => 'Remaining amount', 'type' => 'number', 'totalable' => true]],
            'REP-PAY-001' => [['key' => 'payment_number', 'label' => 'Payment number', 'type' => 'text'], ['key' => 'payment_date', 'label' => 'Payment date', 'type' => 'date'], ['key' => 'supplier.display_name', 'label' => 'Supplier', 'type' => 'text'], ['key' => 'status', 'label' => 'Status', 'type' => 'text'], ['key' => 'net_amount', 'label' => 'Net amount', 'type' => 'number', 'totalable' => true]],
            'REP-INV-001' => [['key' => 'productService.name', 'label' => 'Product', 'type' => 'text'], ['key' => 'warehouse.name', 'label' => 'Warehouse', 'type' => 'text'], ['key' => 'stock_location.name', 'label' => 'Stock location', 'type' => 'text'], ['key' => 'on_hand', 'label' => 'On hand', 'type' => 'number', 'totalable' => true], ['key' => 'reserved', 'label' => 'Reserved', 'type' => 'number', 'totalable' => true], ['key' => 'status', 'label' => 'Status', 'type' => 'text']],
            'REP-INV-002' => [['key' => 'business_date', 'label' => 'Business date', 'type' => 'date'], ['key' => 'document_number', 'label' => 'Document number', 'type' => 'text'], ['key' => 'product.name', 'label' => 'Product', 'type' => 'text'], ['key' => 'movement_type', 'label' => 'Movement type', 'type' => 'text'], ['key' => 'direction', 'label' => 'Direction', 'type' => 'text'], ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'totalable' => true], ['key' => 'running_quantity', 'label' => 'Running quantity', 'type' => 'number']],
            'REP-EXP-002' => [['key' => 'name', 'label' => 'Expense category', 'type' => 'text'], ['key' => 'expense_count', 'label' => 'Expense count', 'type' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'totalable' => true]],
        ];
        foreach ($definitions as $definitionKey => $columns) {
            $definition = DB::table('report_definitions')->where('definition_key', $definitionKey)->where('version', 1)->first();
            if (! $definition) {
                continue;
            }
            $normalized = array_map(fn ($column, $order) => ['key' => $column['key'], 'label' => $column['label'], 'type' => $column['type'], 'visible' => true, 'sortable' => true, 'groupable' => false, 'totalable' => $column['totalable'] ?? false, 'sensitivity' => 'internal', 'order' => $order], $columns, array_keys($columns));
            DB::table('report_definitions')->where('id', $definition->id)->update(['columns_schema' => json_encode($normalized), 'sorting_schema' => json_encode(['allowed' => array_column($normalized, 'key'), 'default' => $normalized[0]['key']]), 'totals_schema' => json_encode(['allowed' => array_values(array_column(array_filter($normalized, fn ($column) => $column['totalable']), 'key')), 'currency_separated' => true]), 'updated_at' => now()]);
            DB::table('report_column_definitions')->where('report_definition_id', $definition->id)->delete();
            foreach ($normalized as $column) {
                DB::table('report_column_definitions')->insert(['id' => (string) Str::uuid(), 'report_definition_id' => $definition->id, 'column_key' => $column['key'], 'label' => $column['label'], 'value_type' => $column['type'], 'visible' => $column['visible'], 'sortable' => $column['sortable'], 'groupable' => $column['groupable'], 'totalable' => $column['totalable'], 'sensitivity' => $column['sensitivity'], 'source_field' => $column['key'], 'display_order' => $column['order'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Source-column alignment is intentionally forward-only in deployed environments.
    }
};
