<?php

namespace Tests\Feature;

use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerRole;
use App\Models\PayableOpenItem;
use App\Models\ProductService;
use App\Models\PurchaseMatchException;
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

class PurchasesPhase7BTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_return_is_quantity_safe_source_linked_to_mds600_and_reversible(): void
    {
        [$owner, $approver, $company, $supplier, $product, $unit, $warehouse, $location, $currency] = $this->context();
        $order = $this->createApprovedOrder($owner, $approver, $company, $supplier, $product, $currency, $warehouse, $location, 5);
        $receipt = $this->createReceipt($owner, $company, $order, 5);

        $created = $this->client($owner, $company)->postJson('/api/v1/purchases/returns', ['goods_receipt_id' => $receipt['id'], 'return_date' => '2026-08-14', 'explanation' => 'Damaged supplier delivery', 'lines' => [['goods_receipt_line_id' => $receipt['line_id'], 'quantity' => 2, 'condition' => 'damaged']]]);
        $created->assertCreated()->assertJsonPath('data.status', 'draft');
        $returnId = $created->json('data.id');
        $this->client($owner, $company)->postJson('/api/v1/purchases/returns/'.$returnId.'/submit')->assertOk()->assertJsonPath('data.status', 'awaiting_approval');
        $this->client($approver, $company)->postJson('/api/v1/purchases/returns/'.$returnId.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $posted = $this->client($owner, $company)->postJson('/api/v1/purchases/returns/'.$returnId.'/post')->assertOk();
        $posted->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('stock_movements', ['source_module' => 'purchases', 'source_id' => $returnId, 'movement_type' => 'purchase_return', 'direction' => 'out', 'quantity' => '2.000000']);
        $this->assertDatabaseHas('inventory_balances', ['product_service_id' => $product->id, 'on_hand' => '3.000000']);
        $this->client($owner, $company)->postJson('/api/v1/purchases/returns', ['goods_receipt_id' => $receipt['id'], 'return_date' => '2026-08-14', 'explanation' => 'Over return', 'lines' => [['goods_receipt_line_id' => $receipt['line_id'], 'quantity' => 4]]])->assertStatus(409)->assertJsonPath('errors.dependency', 'returnable_quantity');
        $this->client($owner, $company)->postJson('/api/v1/purchases/returns/'.$returnId.'/reverse', ['reason' => 'Supplier replacement accepted'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertDatabaseHas('inventory_balances', ['product_service_id' => $product->id, 'on_hand' => '5.000000']);
    }

    public function test_supplier_invoice_reversal_preserves_source_and_reduces_derived_payable(): void
    {
        [$owner, $approver, $company, $supplier, , , , , $currency, $service] = $this->context(true);
        $this->accountTitles($company);
        $invoice = $this->client($owner, $company)->postJson('/api/v1/purchases/invoices', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'invoice_date' => '2026-08-14', 'external_invoice_number' => 'SINV-REV-01', 'lines' => [['product_service_id' => $service->id, 'quantity' => 2, 'unit_cost' => 50]]]);
        $invoice->assertCreated();
        $invoiceId = $invoice->json('data.id');
        $this->client($owner, $company)->postJson('/api/v1/purchases/invoices/'.$invoiceId.'/submit')->assertOk();
        $this->client($approver, $company)->postJson('/api/v1/purchases/invoices/'.$invoiceId.'/approve')->assertOk();
        $this->client($owner, $company)->postJson('/api/v1/purchases/invoices/'.$invoiceId.'/post')->assertOk();
        $this->client($owner, $company)->postJson('/api/v1/purchases/invoices/'.$invoiceId.'/reverse', ['reason' => 'Supplier issued a replacement invoice'])->assertOk();
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoiceId, 'status' => 'reversed', 'remaining_amount' => '0.000000']);
        $payable = PayableOpenItem::where('source_supplier_invoice_id', $invoiceId)->firstOrFail();
        $this->assertSame('0.000000', (string) $payable->remaining_amount);
        $this->assertDatabaseHas('payable_effects', ['payable_open_item_id' => $payable->id, 'source_type' => 'App\\Models\\SupplierInvoiceCorrection', 'effect_type' => 'invoice_reversal_posted', 'amount_delta' => '-100.000000']);
        $this->assertDatabaseHas('accounting_transactions', ['transaction_type' => 'purchases_reversal']);
        $this->assertDatabaseMissing('cash_movements', ['source_record_id' => $invoiceId]);
    }

    public function test_supplier_adjustment_updates_payable_effect_history_and_hold_does_not_pay(): void
    {
        [$owner, $approver, $company, $supplier, , , , , $currency, $service] = $this->context(true);
        $this->accountTitles($company);
        $invoiceId = $this->postServiceInvoice($owner, $approver, $company, $supplier, $currency, $service, 'SINV-ADJ-01', 100);
        $invoice = DB::table('supplier_invoice_lines')->where('supplier_invoice_id', $invoiceId)->first();
        $adjustment = $this->client($owner, $company)->postJson('/api/v1/purchases/adjustments', ['adjustment_type' => 'debit', 'supplier_invoice_id' => $invoiceId, 'currency_id' => $currency->id, 'adjustment_date' => '2026-08-14', 'explanation' => 'Supplier credit for a pricing correction', 'lines' => [['supplier_invoice_line_id' => $invoice->id, 'description' => 'Pricing correction', 'unit_amount' => 20]]]);
        $adjustment->assertCreated();
        $adjustmentId = $adjustment->json('data.id');
        $this->client($owner, $company)->postJson('/api/v1/purchases/adjustments/'.$adjustmentId.'/submit')->assertOk();
        $this->client($approver, $company)->postJson('/api/v1/purchases/adjustments/'.$adjustmentId.'/approve')->assertOk()->assertJsonPath('data.status', 'posted');
        $payable = PayableOpenItem::where('source_supplier_invoice_id', $invoiceId)->firstOrFail();
        $this->assertSame('80.000000', (string) $payable->remaining_amount);
        $this->client($owner, $company)->postJson('/api/v1/purchases/payables/'.$payable->id.'/hold', ['reason' => 'Supplier documentation under review'])->assertOk()->assertJsonPath('data.hold_status', 'held');
        $this->assertSame('80.000000', (string) $payable->fresh()->remaining_amount);
        $this->client($owner, $company)->postJson('/api/v1/purchases/payables/'.$payable->id.'/release', ['reason' => 'Documentation reviewed'])->assertOk()->assertJsonPath('data.hold_status', 'not_held');
        $this->client($owner, $company)->postJson('/api/v1/purchases/adjustments/'.$adjustmentId.'/reverse', ['reason' => 'Correction replaced'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame('100.000000', (string) $payable->fresh()->remaining_amount);
        $this->assertDatabaseHas('payable_effects', ['source_id' => $adjustmentId, 'effect_type' => 'supplier_adjustment_reversed', 'amount_delta' => '20.000000']);
        $this->assertDatabaseHas('accounting_transactions', ['transaction_type' => 'purchases_reversal']);
        $this->assertDatabaseMissing('cash_movements', ['source_record_id' => $adjustmentId]);
    }

    public function test_matching_exception_resolution_preserves_match_history(): void
    {
        [$owner, $approver, $company, $supplier, , , , , $currency, $service] = $this->context(true);
        $order = $this->createApprovedOrder($owner, $approver, $company, $supplier, $service, $currency, null, null, 2, 25);
        $invoice = $this->client($owner, $company)->postJson('/api/v1/purchases/invoices', ['supplier_id' => $supplier->id, 'purchase_order_id' => $order['id'], 'currency_id' => $currency->id, 'invoice_date' => '2026-08-14', 'external_invoice_number' => 'SINV-MATCH-01', 'lines' => [['product_service_id' => $service->id, 'purchase_order_line_id' => $order['line_id'], 'quantity' => 2, 'unit_cost' => 30]]]);
        $invoiceId = $invoice->assertCreated()->json('data.id');
        $this->client($owner, $company)->postJson('/api/v1/purchases/invoices/'.$invoiceId.'/submit')->assertOk()->assertJsonPath('data.status', 'exception');
        $exception = PurchaseMatchException::where('supplier_invoice_id', $invoiceId)->firstOrFail();
        $this->client($owner, $company)->postJson('/api/v1/purchases/match-exceptions/'.$exception->id.'/resolve', ['resolution' => 'Supplier confirmed the corrected unit cost'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoiceId, 'status' => 'awaiting_approval', 'match_status' => 'matched']);
        $this->assertGreaterThanOrEqual(2, DB::table('purchase_match_histories')->where('supplier_invoice_id', $invoiceId)->count());
        $this->client($approver, $company)->postJson('/api/v1/purchases/invoices/'.$invoiceId.'/approve')->assertOk()->assertJsonPath('data.status', 'matched');
    }

    public function test_purchase_order_amendment_creates_revision_and_reapproval_state(): void
    {
        [$owner, $approver, $company, $supplier, $product, , $warehouse, $location, $currency] = $this->context();
        $order = $this->createApprovedOrder($owner, $approver, $company, $supplier, $product, $currency, $warehouse, $location, 4);
        $amended = $this->client($owner, $company)->patchJson('/api/v1/purchases/orders/'.$order['id'].'/amend', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'purchase_date' => '2026-08-14', 'reason' => 'Supplier confirmed a revised quantity', 'lines' => [['product_service_id' => $product->id, 'quantity' => 7, 'unit_cost' => 25, 'warehouse_id' => $warehouse->id, 'stock_location_id' => $location->id]]]);
        $amended->assertOk()->assertJsonPath('data.status', 'awaiting_approval');
        $this->assertDatabaseHas('purchase_order_revisions', ['purchase_order_id' => $order['id'], 'revision_type' => 'amendment']);
        $this->client($approver, $company)->postJson('/api/v1/purchases/orders/'.$order['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('inventory_balances', ['product_service_id' => $product->id, 'incoming' => '7.000000']);
    }

    private function client(User $user, $company)
    {
        return $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
    }

    private function createApprovedOrder(User $owner, User $approver, $company, $supplier, ProductService $product, ReferenceCurrency $currency, ?Warehouse $warehouse, ?StockLocation $location, int $quantity, int $unitCost = 25): array
    {
        $line = ['product_service_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $unitCost];
        if ($warehouse) {
            $line['warehouse_id'] = $warehouse->id;
            $line['stock_location_id'] = $location->id;
        }
        $created = $this->client($owner, $company)->postJson('/api/v1/purchases/orders', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'purchase_date' => '2026-08-14', 'lines' => [$line]]);
        $created->assertCreated();
        $id = $created->json('data.id');
        $lineId = $created->json('data.lines.0.id');
        $this->client($owner, $company)->postJson('/api/v1/purchases/orders/'.$id.'/submit')->assertOk();
        $this->client($approver, $company)->postJson('/api/v1/purchases/orders/'.$id.'/approve')->assertOk();

        return ['id' => $id, 'line_id' => $lineId];
    }

    private function createReceipt(User $owner, $company, array $order, int $quantity): array
    {
        $created = $this->client($owner, $company)->postJson('/api/v1/purchases/receipts', ['purchase_order_id' => $order['id'], 'receipt_date' => '2026-08-14', 'lines' => [['purchase_order_line_id' => $order['line_id'], 'quantity' => $quantity]]]);
        $created->assertCreated();
        $id = $created->json('data.id');
        $lineId = $created->json('data.lines.0.id');
        $this->client($owner, $company)->postJson('/api/v1/purchases/receipts/'.$id.'/submit')->assertOk();
        $this->client($owner, $company)->postJson('/api/v1/purchases/receipts/'.$id.'/post')->assertOk();

        return ['id' => $id, 'line_id' => $lineId];
    }

    private function postServiceInvoice(User $owner, User $approver, $company, $supplier, $currency, $service, string $external, int $amount): string
    {
        $created = $this->client($owner, $company)->postJson('/api/v1/purchases/invoices', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'invoice_date' => '2026-08-14', 'external_invoice_number' => $external, 'lines' => [['product_service_id' => $service->id, 'quantity' => 1, 'unit_cost' => $amount]]]);
        $id = $created->assertCreated()->json('data.id');
        $this->client($owner, $company)->postJson('/api/v1/purchases/invoices/'.$id.'/submit')->assertOk();
        $this->client($approver, $company)->postJson('/api/v1/purchases/invoices/'.$id.'/approve')->assertOk();
        $this->client($owner, $company)->postJson('/api/v1/purchases/invoices/'.$id.'/post')->assertOk();

        return $id;
    }

    private function context(bool $withService = false): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Purchases 7B Owner', 'email' => 'purchases-7b-owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Purchases 7B Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));
        $company = $owner->companies()->firstOrFail();
        $approver = User::create(['name' => 'Purchases 7B Approver', 'email' => 'purchases-7b-approver@example.test', 'password' => Hash::make('password-123'), 'status' => 'active']);
        DB::table('company_user')->insert(['company_id' => $company->id, 'user_id' => $approver->id, 'status' => 'active', 'is_owner' => false, 'created_at' => now(), 'updated_at' => now()]);
        $role = Role::where('company_id', $company->id)->where('system_key', 'administrator')->firstOrFail();
        DB::table('role_user')->insert(['role_id' => $role->id, 'user_id' => $approver->id, 'company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);
        $unit = UnitOfMeasure::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'PCS', 'normalized_code' => 'pcs', 'name' => 'Pieces', 'unit_type' => 'quantity', 'decimal_precision' => 0, 'allows_fractional' => false, 'status' => 'active', 'version' => 1]);
        $product = ProductService::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'PRD-7B', 'normalized_code' => 'prd-7b', 'name' => 'Phase 7B stock item', 'record_type' => 'product', 'base_unit_id' => $unit->id, 'purchasable' => true, 'sellable' => true, 'stock_managed' => true, 'non_stock' => false, 'standard_purchase_price' => 25, 'status' => 'active', 'version' => 1]);
        $service = ProductService::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'SVC-7B', 'normalized_code' => 'svc-7b', 'name' => 'Phase 7B service', 'record_type' => 'service', 'base_unit_id' => $unit->id, 'purchasable' => true, 'sellable' => false, 'stock_managed' => false, 'non_stock' => true, 'standard_purchase_price' => 50, 'status' => 'active', 'version' => 1]);
        $warehouse = Warehouse::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'WH-7B', 'normalized_code' => 'wh-7b', 'name' => 'Phase 7B Warehouse', 'status' => 'active', 'version' => 1]);
        $location = StockLocation::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'code' => 'LOC-7B', 'normalized_code' => 'loc-7b', 'name' => 'Phase 7B Location', 'status' => 'active', 'version' => 1]);
        $supplier = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'SUP-7B', 'normalized_code' => 'sup-7b', 'party_type' => 'organization', 'official_name' => 'Phase 7B Supplier', 'display_name' => 'Phase 7B Supplier', 'status' => 'active', 'version' => 1]);
        BusinessPartnerRole::create(['id' => (string) Str::uuid(), 'business_partner_id' => $supplier->id, 'company_id' => $company->id, 'role' => 'supplier', 'status' => 'active', 'version' => 1]);
        $currency = ReferenceCurrency::where('company_id', $company->id)->where('code', 'PHP')->firstOrFail();

        return $withService ? [$owner, $approver, $company, $supplier, $product, $unit, $warehouse, $location, $currency, $service] : [$owner, $approver, $company, $supplier, $product, $unit, $warehouse, $location, $currency];
    }

    private function accountTitles($company): void
    {
        foreach ([['AP-7B', 'Accounts Payable', 'liability', 'credit', 'payable'], ['INV-7B', 'Inventory', 'asset', 'debit', 'inventory'], ['EXP-7B', 'Purchase Expense', 'expense', 'debit', 'expense'], ['TAX-7B', 'Recoverable Tax', 'asset', 'debit', 'tax']] as [$code, $name, $classification, $normal, $subtype]) {
            AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => $code, 'normalized_code' => strtolower($code), 'name' => $name, 'classification' => $classification, 'normal_balance' => $normal, 'account_subtype' => $subtype, 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        }
    }
}
