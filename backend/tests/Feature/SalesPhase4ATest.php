<?php

namespace Tests\Feature;

use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerRole;
use App\Models\Company;
use App\Models\PaymentTerm;
use App\Models\ProductService;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesPhase4ATest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_sale_calculates_on_server_and_draft_is_editable(): void
    {
        [$user, $company, $customer, $service, $term] = $this->salesContext();
        $response = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id)->withHeader('Idempotency-Key', 'sale-draft')->postJson('/api/v1/sales', ['sale_type' => 'credit_sale', 'payment_basis' => 'credit', 'sale_date' => '2026-08-04', 'customer_id' => $customer->id, 'payment_term_id' => $term->id, 'lines' => [['product_service_id' => $service->id, 'quantity' => 2, 'unit_price' => 125, 'discount_type' => 'percent', 'discount_value' => 10]]]);
        $response->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total', '225.000000');
        $this->assertDatabaseHas('sale_lines', ['item_code_snapshot' => 'SVC-001', 'discount_amount' => '25.000000']);
        $saleId = $response->json('data.id');
        $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id)->patchJson('/api/v1/sales/'.$saleId, ['version' => 1, 'notes' => 'Updated draft'])->assertOk()->assertJsonPath('data.notes', 'Updated draft');
    }

    public function test_stock_sales_require_inventory_location_and_cash_sales_remain_deferred(): void
    {
        [$user, $company, $customer, $service, $term] = $this->salesContext();
        $stock = ProductService::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'PRD-001', 'normalized_code' => 'prd-001', 'name' => 'Stock item', 'record_type' => 'product', 'sellable' => true, 'stock_managed' => true, 'non_stock' => false, 'status' => 'active', 'version' => 1]);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $client->postJson('/api/v1/sales', ['sale_type' => 'credit_sale', 'payment_basis' => 'credit', 'sale_date' => '2026-08-04', 'customer_id' => $customer->id, 'payment_term_id' => $term->id, 'lines' => [['product_service_id' => $stock->id, 'quantity' => 1, 'unit_price' => 100]]])->assertStatus(409)->assertJsonPath('errors.dependency', 'inventory_location');
        $cashSale = $client->postJson('/api/v1/sales', ['sale_type' => 'cash_sale', 'payment_basis' => 'cash', 'sale_date' => '2026-08-04', 'lines' => [['product_service_id' => $service->id, 'quantity' => 1, 'unit_price' => 100, 'price_override_reason' => 'Tested dependency boundary']]])->json('data.id');
        Sale::whereKey($cashSale)->update(['status' => 'approved']);
        $client->postJson('/api/v1/sales/'.$cashSale.'/post')->assertStatus(409)->assertJsonPath('errors.dependency', 'collections');
    }

    public function test_posted_credit_sale_creates_only_receivable_and_accounting_effects(): void
    {
        [$user, $company, $customer, $service, $term] = $this->salesContext();
        $this->accountTitles($company);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $saleId = $client->postJson('/api/v1/sales', ['sale_type' => 'credit_sale', 'payment_basis' => 'credit', 'sale_date' => '2026-08-04', 'customer_id' => $customer->id, 'payment_term_id' => $term->id, 'lines' => [['product_service_id' => $service->id, 'quantity' => 1, 'unit_price' => 100, 'price_override_reason' => 'Tested accounting effect']]])->json('data.id');
        Sale::whereKey($saleId)->update(['status' => 'approved']);
        $posted = $client->postJson('/api/v1/sales/'.$saleId.'/post');
        $posted->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('receivable_open_items', ['source_sale_id' => $saleId, 'remaining_amount' => '100.000000']);
        $this->assertDatabaseHas('business_transactions', ['transaction_type' => 'credit_sale']);
        $this->assertDatabaseMissing('cash_movements', ['source_record_id' => $saleId]);
    }

    public function test_billing_statement_is_a_read_only_snapshot_of_existing_open_items(): void
    {
        [$user, $company, $customer, $service, $term] = $this->salesContext();
        $this->accountTitles($company);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $saleId = $client->postJson('/api/v1/sales', ['sale_type' => 'credit_sale', 'payment_basis' => 'credit', 'sale_date' => '2026-08-04', 'customer_id' => $customer->id, 'payment_term_id' => $term->id, 'lines' => [['product_service_id' => $service->id, 'quantity' => 1, 'unit_price' => 100, 'price_override_reason' => 'Tested statement effect']]])->json('data.id');
        Sale::whereKey($saleId)->update(['status' => 'approved']);
        $client->postJson('/api/v1/sales/'.$saleId.'/post')->assertOk();
        $openItemCount = (int) $this->app->make('db')->table('receivable_open_items')->count();
        $statement = $client->postJson('/api/v1/billing-statements', ['customer_id' => $customer->id, 'statement_date' => '2026-08-04', 'period_from' => '2026-08-01', 'period_to' => '2026-08-04']);
        $statement->assertCreated()->assertJsonPath('data.status', 'generated')->assertJsonPath('data.ending_balance', '100.000000')->assertJsonPath('data.source_snapshot.open_item_snapshots.0.remaining_amount', '100.000000');
        $this->assertSame($openItemCount, (int) $this->app->make('db')->table('receivable_open_items')->count());
    }

    private function salesContext(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Sales Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = $user->companies()->firstOrFail();
        $customer = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CUS-001', 'normalized_code' => 'cus-001', 'party_type' => 'organization', 'official_name' => 'Customer One', 'display_name' => 'Customer One', 'status' => 'active', 'version' => 1]);
        BusinessPartnerRole::create(['id' => (string) Str::uuid(), 'business_partner_id' => $customer->id, 'company_id' => $company->id, 'role' => 'customer', 'status' => 'active', 'version' => 1]);
        $service = ProductService::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'SVC-001', 'normalized_code' => 'svc-001', 'name' => 'Consulting', 'record_type' => 'service', 'sellable' => true, 'purchasable' => false, 'stock_managed' => false, 'non_stock' => true, 'standard_selling_price' => 125, 'status' => 'active', 'version' => 1]);
        $term = PaymentTerm::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'NET-30', 'normalized_code' => 'net-30', 'name' => 'Net 30', 'term_type' => 'due_days', 'due_days' => 30, 'end_of_month' => false, 'status' => 'active', 'version' => 1]);

        return [$user, $company, $customer, $service, $term];
    }

    private function accountTitles(Company $company): void
    {
        foreach ([['AR-001', 'Accounts Receivable', 'asset', 'debit', 'receivable'], ['REV-001', 'Sales Revenue', 'income', 'credit', 'revenue']] as [$code, $name, $classification, $normal, $subtype]) {
            AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => $code, 'normalized_code' => strtolower($code), 'name' => $name, 'classification' => $classification, 'normal_balance' => $normal, 'account_subtype' => $subtype, 'posting_eligible' => true, 'system_standard' => false, 'locked' => false, 'status' => 'active', 'version' => 1]);
        }
    }
}
