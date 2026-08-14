<?php

namespace Tests\Feature;

use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerRole;
use App\Models\PaymentTerm;
use App\Models\ProductService;
use App\Models\ReferenceCurrency;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasesPhase7ATest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_approval_drives_incoming_and_partial_receipts_drive_stock(): void
    {
        [$owner, $approver, $company, $supplier, $product, $unit, $warehouse, $location, $currency] = $this->context();
        $order = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->withHeader('Idempotency-Key', 'po-create')->postJson('/api/v1/purchases/orders', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'purchase_date' => '2026-08-14', 'lines' => [['product_service_id' => $product->id, 'quantity' => 10, 'unit_cost' => 25, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id]]]);
        $order->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total', '250.000000');
        $orderId = $order->json('data.id');
        $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/purchases/orders/'.$orderId.'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->actingAs($approver, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/purchases/orders/'.$orderId.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('inventory_balances', ['company_id' => $company->id, 'product_service_id' => $product->id, 'incoming' => '10.000000', 'on_hand' => '0.000000']);

        $receipt = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/purchases/receipts', ['purchase_order_id' => $orderId, 'receipt_date' => '2026-08-14', 'lines' => [['purchase_order_line_id' => $order->json('data.lines.0.id'), 'quantity' => 4]]]);
        $receipt->assertCreated();
        $receiptId = $receipt->json('data.id');
        $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/purchases/receipts/'.$receiptId.'/submit')->assertOk()->assertJsonPath('data.status', 'validating');
        $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/purchases/receipts/'.$receiptId.'/post')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('inventory_balances', ['product_service_id' => $product->id, 'on_hand' => '4.000000', 'incoming' => '6.000000']);
        $this->assertDatabaseHas('stock_movements', ['source_module' => 'purchases', 'source_id' => $receiptId, 'movement_type' => 'purchase_receipt', 'quantity' => '4.000000']);
        $this->assertDatabaseMissing('cash_movements', ['source_record_id' => $receiptId]);

        $second = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->withHeader('Idempotency-Key', 'receipt-create-2')->postJson('/api/v1/purchases/receipts', ['purchase_order_id' => $orderId, 'receipt_date' => '2026-08-15', 'lines' => [['purchase_order_line_id' => $order->json('data.lines.0.id'), 'quantity' => 6]]]);
        $second->assertCreated();
        $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/purchases/receipts/'.$second->json('data.id').'/submit')->assertOk();
        $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/purchases/receipts/'.$second->json('data.id').'/post')->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['product_service_id' => $product->id, 'on_hand' => '10.000000', 'incoming' => '0.000000']);
    }

    public function test_supplier_invoice_posts_one_payable_and_never_creates_payment(): void
    {
        [$owner, $approver, $company, $supplier, $product, $unit, , , $currency, $service] = $this->context(true);
        $this->accountTitles($company);
        $invoice = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->withHeader('Idempotency-Key', 'invoice-create')->postJson('/api/v1/purchases/invoices', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'invoice_date' => '2026-08-14', 'external_invoice_number' => 'SUP-100', 'lines' => [['product_service_id' => $service->id, 'quantity' => 2, 'unit_cost' => 75]]]);
        $invoice->assertCreated()->assertJsonPath('data.total', '150.000000');
        $invoiceId = $invoice->json('data.id');
        $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/purchases/invoices/'.$invoiceId.'/submit')->assertOk()->assertJsonPath('data.status', 'awaiting_approval');
        $this->actingAs($approver, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/purchases/invoices/'.$invoiceId.'/approve')->assertOk()->assertJsonPath('data.status', 'matched');
        $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/purchases/invoices/'.$invoiceId.'/post')->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('payable_open_items', ['source_supplier_invoice_id' => $invoiceId, 'original_amount' => '150.000000', 'remaining_amount' => '150.000000']);
        $this->assertDatabaseHas('business_transactions', ['transaction_type' => 'supplier_invoice']);
        $this->assertDatabaseHas('accounting_transactions', ['transaction_type' => 'supplier_invoice']);
        $this->assertDatabaseMissing('cash_movements', ['source_record_id' => $invoiceId]);
        $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id)->withHeader('Idempotency-Key', 'invoice-create-duplicate')->postJson('/api/v1/purchases/invoices', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'invoice_date' => '2026-08-14', 'external_invoice_number' => 'SUP-100', 'lines' => [['product_service_id' => $service->id, 'quantity' => 1, 'unit_cost' => 75]]])->assertStatus(409)->assertJsonPath('errors.duplicate', true);
    }

    public function test_supplier_and_company_scope_are_enforced_for_purchase_orders(): void
    {
        [$owner, , $company, $supplier, $product, , $warehouse, $location, $currency] = $this->context();
        BusinessPartner::whereKey($supplier->id)->update(['status' => 'inactive']);
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $client->postJson('/api/v1/purchases/orders', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'purchase_date' => '2026-08-14', 'lines' => [['product_service_id' => $product->id, 'quantity' => 1, 'unit_cost' => 10, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id]]])->assertStatus(409)->assertJsonPath('errors.dependency', 'supplier');
    }

    private function context(bool $withService = false): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Purchases Owner', 'email' => 'purchases-owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Purchases Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));
        $company = $owner->companies()->firstOrFail();
        $approver = User::create(['name' => 'Purchases Approver', 'email' => 'purchases-approver@example.test', 'password' => Hash::make('password-123'), 'status' => 'active']);
        DB::table('company_user')->insert(['company_id' => $company->id, 'user_id' => $approver->id, 'status' => 'active', 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()]);
        $role = Role::where('company_id', $company->id)->where('system_key', 'administrator')->firstOrFail();
        DB::table('role_user')->insert(['role_id' => $role->id, 'user_id' => $approver->id, 'company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);
        $unit = UnitOfMeasure::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'PCS', 'normalized_code' => 'pcs', 'name' => 'Pieces', 'unit_type' => 'quantity', 'decimal_precision' => 0, 'allows_fractional' => false, 'status' => 'active', 'version' => 1]);
        $product = ProductService::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'PRD-PO', 'normalized_code' => 'prd-po', 'name' => 'Purchase stock item', 'record_type' => 'product', 'base_unit_id' => $unit->id, 'purchasable' => true, 'sellable' => true, 'stock_managed' => true, 'non_stock' => false, 'standard_purchase_price' => 25, 'status' => 'active', 'version' => 1]);
        $service = ProductService::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'SVC-PO', 'normalized_code' => 'svc-po', 'name' => 'Supplier service', 'record_type' => 'service', 'base_unit_id' => $unit->id, 'purchasable' => true, 'sellable' => false, 'stock_managed' => false, 'non_stock' => true, 'standard_purchase_price' => 75, 'status' => 'active', 'version' => 1]);
        $warehouse = Warehouse::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'WH-PO', 'normalized_code' => 'wh-po', 'name' => 'Purchases Warehouse', 'status' => 'active', 'version' => 1]);
        $location = StockLocation::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'code' => 'PO-SHELF', 'normalized_code' => 'po-shelf', 'name' => 'Purchases Shelf', 'status' => 'active', 'version' => 1]);
        $supplier = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'SUP-PO', 'normalized_code' => 'sup-po', 'party_type' => 'organization', 'official_name' => 'Supplier One', 'display_name' => 'Supplier One', 'status' => 'active', 'version' => 1]);
        BusinessPartnerRole::create(['id' => (string) Str::uuid(), 'business_partner_id' => $supplier->id, 'company_id' => $company->id, 'role' => 'supplier', 'status' => 'active', 'version' => 1]);
        $currency = ReferenceCurrency::where('company_id', $company->id)->where('code', 'PHP')->firstOrFail();
        PaymentTerm::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'NET-30-PO', 'normalized_code' => 'net-30-po', 'name' => 'Net 30', 'term_type' => 'due_days', 'due_days' => 30, 'end_of_month' => false, 'status' => 'active', 'version' => 1]);

        return $withService ? [$owner, $approver, $company, $supplier, $product, $unit, $warehouse, $location, $currency, $service] : [$owner, $approver, $company, $supplier, $product, $unit, $warehouse, $location, $currency];
    }

    private function accountTitles($company): void
    {
        foreach ([['AP-001', 'Accounts Payable', 'liability', 'credit', 'payable'], ['INV-001', 'Inventory', 'asset', 'debit', 'inventory'], ['EXP-001', 'Purchase Expense', 'expense', 'debit', 'expense'], ['TAX-001', 'Recoverable Tax', 'asset', 'debit', 'tax']] as [$code, $name, $classification, $normal, $subtype]) {
            AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => $code, 'normalized_code' => strtolower($code), 'name' => $name, 'classification' => $classification, 'normal_balance' => $normal, 'account_subtype' => $subtype, 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        }
    }
}
