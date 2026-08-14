<?php

namespace Tests\Feature;

use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerRole;
use App\Models\InventoryAdjustment;
use App\Models\PaymentTerm;
use App\Models\ProductService;
use App\Models\ReasonCode;
use App\Models\Sale;
use App\Models\StockLocation;
use App\Models\StockReservation;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryPhase6BTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjustment_requires_reason_posts_through_movement_engine_and_reverses(): void
    {
        [$user, $company, $product, $unit, $warehouse, $location] = $this->inventoryContext();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $this->seedOpening($client, $product, $unit, $warehouse, $location, 10);
        $reason = ReasonCode::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'ADJ', 'normalized_code' => 'adj', 'name' => 'Inventory adjustment', 'domain' => 'INVENTORY_ADJUSTMENT', 'requires_explanation' => true, 'requires_evidence' => false, 'status' => 'active', 'version' => 1]);
        $payload = ['business_date' => '2026-08-14', 'reason_code_id' => $reason->id, 'explanation' => 'Damaged stock write-off', 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'unit_of_measure_id' => $unit->id, 'quantity' => 2, 'direction' => 'out']]];
        $adjustment = $client->postJson('/api/v1/inventory/adjustments', $payload)->assertCreated()->json('data.id');
        $client->postJson("/api/v1/inventory/adjustments/{$adjustment}/submit")->assertOk();
        $client->postJson("/api/v1/inventory/adjustments/{$adjustment}/review")->assertOk();
        InventoryAdjustment::whereKey($adjustment)->update(['submitted_by' => null, 'created_by' => null]);
        $client->postJson("/api/v1/inventory/adjustments/{$adjustment}/approve")->assertOk();
        $client->postJson("/api/v1/inventory/adjustments/{$adjustment}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $location->id, 'on_hand' => '8.000000']);
        $client->postJson("/api/v1/inventory/adjustments/{$adjustment}/reverse")->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $location->id, 'on_hand' => '10.000000']);
    }

    public function test_physical_count_snapshots_entries_and_posts_only_the_variance(): void
    {
        [$user, $company, $product, $unit, $warehouse, $location] = $this->inventoryContext();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $this->seedOpening($client, $product, $unit, $warehouse, $location, 10);
        $count = $client->postJson('/api/v1/inventory/counts', ['business_date' => '2026-08-14', 'mode' => 'spot', 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'product_service_ids' => [$product->id]])->assertCreated()->json('data.id');
        $client->postJson("/api/v1/inventory/counts/{$count}/start")->assertOk();
        $item = $client->getJson("/api/v1/inventory/counts/{$count}")->json('data.items.0.id');
        $client->postJson("/api/v1/inventory/counts/{$count}/entries", ['item_id' => $item, 'counted_quantity' => 8])->assertOk()->assertJsonPath('data.items.0.variance_quantity', '-2.000000');
        $client->postJson("/api/v1/inventory/counts/{$count}/submit")->assertOk();
        $client->postJson("/api/v1/inventory/counts/{$count}/review")->assertOk();
        $client->postJson("/api/v1/inventory/counts/{$count}/approve")->assertOk();
        $client->postJson("/api/v1/inventory/counts/{$count}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $client->postJson("/api/v1/inventory/counts/{$count}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $location->id, 'on_hand' => '8.000000']);
    }

    public function test_reservation_holds_available_stock_and_sales_consume_it_once(): void
    {
        [$user, $company, $product, $unit, $warehouse, $location, $customer, $term] = $this->inventoryContext(true);
        AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'AR-001', 'normalized_code' => 'ar-001', 'name' => 'Accounts Receivable', 'classification' => 'asset', 'normal_balance' => 'debit', 'account_subtype' => 'receivable', 'posting_eligible' => true, 'version' => 1]);
        AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'REV-001', 'normalized_code' => 'rev-001', 'name' => 'Sales Revenue', 'classification' => 'income', 'normal_balance' => 'credit', 'account_subtype' => 'revenue', 'posting_eligible' => true, 'version' => 1]);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $this->seedOpening($client, $product, $unit, $warehouse, $location, 5);
        $sale = $client->postJson('/api/v1/sales', ['sale_type' => 'credit_sale', 'payment_basis' => 'credit', 'sale_date' => '2026-08-14', 'customer_id' => $customer->id, 'payment_term_id' => $term->id, 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'quantity' => 2, 'unit_price' => 100]]])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $location->id, 'reserved' => '2.000000']);
        Sale::whereKey($sale)->update(['status' => 'approved']);
        $client->postJson("/api/v1/sales/{$sale}/post")->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $location->id, 'on_hand' => '3.000000', 'reserved' => '0.000000']);
        $this->assertSame(1, StockReservation::where('source_id', $sale)->where('status', 'consumed')->count());
    }

    public function test_reorder_attention_stock_card_report_and_barcode_are_company_scoped(): void
    {
        [$user, $company, $product, $unit, $warehouse, $location] = $this->inventoryContext();
        $product->barcode = '8900001';
        $product->save();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $this->seedOpening($client, $product, $unit, $warehouse, $location, 2);
        $client->postJson('/api/v1/inventory/reorder-rules', ['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'reorder_point' => 5])->assertCreated();
        $client->getJson('/api/v1/inventory/attention')->assertOk()->assertJsonPath('data.items.0.type', 'low_stock');
        $client->getJson('/api/v1/inventory/stock-card?product_id='.$product->id)->assertOk()->assertJsonPath('data.quantity_basis', 'posted movements in business-date, posted-at, id order');
        $client->getJson('/api/v1/inventory/reports/low_stock')->assertOk()->assertJsonPath('data.meta.report', 'low_stock');
        $client->getJson('/api/v1/inventory/barcode/8900001')->assertOk()->assertJsonPath('data.name', 'Stock item');
        $client->getJson('/api/v1/inventory/barcode/not-found')->assertNotFound();
    }

    private function seedOpening($client, ProductService $product, UnitOfMeasure $unit, Warehouse $warehouse, StockLocation $location, int $quantity): void
    {
        $opening = $client->postJson('/api/v1/inventory/opening-stock', ['business_date' => '2026-08-14', 'explanation' => 'Opening stock', 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'unit_of_measure_id' => $unit->id, 'quantity' => $quantity]]])->assertCreated()->json('data.id');
        $client->postJson("/api/v1/inventory/opening-stock/{$opening}/post")->assertOk();
    }

    private function inventoryContext(bool $withCustomer = false): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Inventory Owner', 'email' => 'inventory@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Inventory Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = $user->companies()->firstOrFail();
        $unit = UnitOfMeasure::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'PCS', 'normalized_code' => 'pcs', 'name' => 'Pieces', 'unit_type' => 'quantity', 'decimal_precision' => 0, 'allows_fractional' => false, 'status' => 'active', 'version' => 1]);
        $product = ProductService::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'PRD-001', 'normalized_code' => 'prd-001', 'name' => 'Stock item', 'record_type' => 'product', 'base_unit_id' => $unit->id, 'sellable' => true, 'stock_managed' => true, 'non_stock' => false, 'standard_selling_price' => 100, 'status' => 'active', 'version' => 1]);
        $warehouse = Warehouse::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'WH-001', 'normalized_code' => 'wh-001', 'name' => 'Main Warehouse', 'status' => 'active', 'version' => 1]);
        $location = StockLocation::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'normalized_code' => 'main', 'name' => 'Main Shelf', 'status' => 'active', 'version' => 1]);
        if (! $withCustomer) {
            return [$user, $company, $product, $unit, $warehouse, $location];
        }
        $customer = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CUS-001', 'normalized_code' => 'cus-001', 'party_type' => 'organization', 'official_name' => 'Customer One', 'display_name' => 'Customer One', 'status' => 'active', 'version' => 1]);
        BusinessPartnerRole::create(['id' => (string) Str::uuid(), 'business_partner_id' => $customer->id, 'company_id' => $company->id, 'role' => 'customer', 'status' => 'active', 'version' => 1]);
        $term = PaymentTerm::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'NET-30', 'normalized_code' => 'net-30', 'name' => 'Net 30', 'term_type' => 'due_days', 'due_days' => 30, 'end_of_month' => false, 'status' => 'active', 'version' => 1]);

        return [$user, $company, $product, $unit, $warehouse, $location, $customer, $term];
    }
}
