<?php

namespace Tests\Feature;

use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerRole;
use App\Models\PaymentTerm;
use App\Models\ProductService;
use App\Models\Sale;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryPhase6ATest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_stock_and_receipt_derive_balance_and_duplicate_post_is_safe(): void
    {
        [$user, $company, $product, $unit, $warehouse, $location] = $this->inventoryContext();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $opening = $client->withHeader('Idempotency-Key', 'opening-create')->postJson('/api/v1/inventory/opening-stock', ['business_date' => '2026-08-14', 'explanation' => 'Approved opening stock count', 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'unit_of_measure_id' => $unit->id, 'quantity' => 10]]]);
        $opening->assertCreated();
        $openingId = $opening->json('data.id');
        $client->withHeader('Idempotency-Key', 'opening-post')->postJson('/api/v1/inventory/opening-stock/'.$openingId.'/post')->assertOk()->assertJsonPath('data.status', 'posted');
        $client->withHeader('Idempotency-Key', 'opening-post')->postJson('/api/v1/inventory/opening-stock/'.$openingId.'/post')->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertDatabaseHas('inventory_balances', ['company_id' => $company->id, 'product_service_id' => $product->id, 'on_hand' => '10.000000']);
        $receipt = $client->postJson('/api/v1/inventory/receipts', ['business_date' => '2026-08-14', 'explanation' => 'Direct approved receipt', 'source_reference' => 'DEL-001', 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'quantity' => 5]]]);
        $receipt->assertCreated();
        $client->postJson('/api/v1/inventory/receipts/'.$receipt->json('data.id').'/post')->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['product_service_id' => $product->id, 'on_hand' => '15.000000']);
        $this->assertSame(2, StockMovement::where('company_id', $company->id)->count());
    }

    public function test_draft_update_replaces_lines_with_version_control_before_posting(): void
    {
        [$user, $company, $product, $unit, $warehouse, $location] = $this->inventoryContext();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $opening = $client->postJson('/api/v1/inventory/opening-stock', ['business_date' => '2026-08-14', 'explanation' => 'Draft count', 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'quantity' => 10]]])->json('data.id');
        $client->withHeader('Idempotency-Key', 'opening-update')->patchJson('/api/v1/inventory/opening-stock/'.$opening, ['version' => 1, 'business_date' => '2026-08-14', 'explanation' => 'Corrected draft count', 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'unit_of_measure_id' => $unit->id, 'quantity' => 12]]])->assertOk()->assertJsonPath('data.version', 2);
        $client->withHeader('Idempotency-Key', 'opening-update-stale')->patchJson('/api/v1/inventory/opening-stock/'.$opening, ['version' => 1, 'business_date' => '2026-08-14', 'explanation' => 'Stale draft', 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'quantity' => 9]]])->assertStatus(409)->assertJsonPath('errors.version_conflict', true);
        $client->postJson('/api/v1/inventory/opening-stock/'.$opening.'/post')->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $location->id, 'on_hand' => '12.000000']);
    }

    public function test_issue_rejects_insufficient_stock_and_transfer_moves_both_legs_then_reverses(): void
    {
        [$user, $company, $product, $unit, $warehouse, $location] = $this->inventoryContext();
        $destinationWarehouse = Warehouse::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'WH-002', 'normalized_code' => 'wh-002', 'name' => 'Outlet Warehouse', 'status' => 'active', 'version' => 1]);
        $destination = StockLocation::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'warehouse_id' => $destinationWarehouse->id, 'code' => 'OUT', 'normalized_code' => 'out', 'name' => 'Outlet Shelf', 'status' => 'active', 'version' => 1]);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $opening = $client->postJson('/api/v1/inventory/opening-stock', ['business_date' => '2026-08-14', 'explanation' => 'Opening', 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'quantity' => 4]]])->json('data.id');
        $client->postJson('/api/v1/inventory/opening-stock/'.$opening.'/post')->assertOk();
        $issue = $client->postJson('/api/v1/inventory/issues', ['business_date' => '2026-08-14', 'explanation' => 'Internal use', 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'quantity' => 5]]])->json('data.id');
        $client->postJson('/api/v1/inventory/issues/'.$issue.'/post')->assertStatus(409)->assertJsonPath('errors.dependency', 'inventory_availability');
        $transfer = $client->postJson('/api/v1/inventory/transfers', ['business_date' => '2026-08-14', 'explanation' => 'Move to outlet', 'source_warehouse_id' => $warehouse->id, 'source_stock_location_id' => $location->id, 'destination_warehouse_id' => $destinationWarehouse->id, 'destination_stock_location_id' => $destination->id, 'lines' => [['product_service_id' => $product->id, 'quantity' => 3]]])->json('data.id');
        $client->postJson('/api/v1/inventory/transfers/'.$transfer.'/post')->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $location->id, 'on_hand' => '1.000000']);
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $destination->id, 'on_hand' => '3.000000']);
        $client->postJson('/api/v1/inventory/transfers/'.$transfer.'/reverse')->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $location->id, 'on_hand' => '4.000000']);
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $destination->id, 'on_hand' => '0.000000']);
    }

    public function test_stock_managed_credit_sale_posts_one_sale_issue_and_service_sale_has_no_stock_effect(): void
    {
        [$user, $company, $product, $unit, $warehouse, $location, $customer, $term] = $this->inventoryContext(true);
        AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'AR-001', 'normalized_code' => 'ar-001', 'name' => 'Accounts Receivable', 'classification' => 'asset', 'normal_balance' => 'debit', 'account_subtype' => 'receivable', 'posting_eligible' => true, 'version' => 1]);
        AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'REV-001', 'normalized_code' => 'rev-001', 'name' => 'Sales Revenue', 'classification' => 'income', 'normal_balance' => 'credit', 'account_subtype' => 'revenue', 'posting_eligible' => true, 'version' => 1]);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $opening = $client->postJson('/api/v1/inventory/opening-stock', ['business_date' => '2026-08-14', 'explanation' => 'Opening', 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'quantity' => 5]]])->json('data.id');
        $client->postJson('/api/v1/inventory/opening-stock/'.$opening.'/post')->assertOk();
        $sale = $client->postJson('/api/v1/sales', ['sale_type' => 'credit_sale', 'payment_basis' => 'credit', 'sale_date' => '2026-08-14', 'customer_id' => $customer->id, 'payment_term_id' => $term->id, 'lines' => [['product_service_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id, 'quantity' => 2, 'unit_price' => 100]]])->json('data.id');
        Sale::whereKey($sale)->update(['status' => 'approved']);
        $client->postJson('/api/v1/sales/'.$sale.'/post')->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('stock_movements', ['source_id' => $sale, 'movement_type' => 'sale_issue', 'quantity' => '2.000000']);
        $this->assertDatabaseHas('inventory_balances', ['stock_location_id' => $location->id, 'on_hand' => '3.000000']);
        $this->assertSame(1, StockMovement::where('source_id', $sale)->where('movement_type', 'sale_issue')->count());
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
