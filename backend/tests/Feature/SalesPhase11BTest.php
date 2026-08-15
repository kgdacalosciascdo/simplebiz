<?php

namespace Tests\Feature;

use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerRole;
use App\Models\CashAccount;
use App\Models\CashAccountCapability;
use App\Models\CashAccountType;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\ProductService;
use App\Models\ReferenceCurrency;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesPhase11BTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_now_sale_posts_sale_receipt_application_and_cash_effect_once(): void
    {
        [$user, $company, $customer, $service, $currency, $account, $method] = $this->context();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $saleId = $client->postJson('/api/v1/sales', [
            'sale_type' => 'cash_sale',
            'payment_basis' => 'cash',
            'sale_date' => '2026-08-15',
            'customer_id' => $customer->id,
            'currency_id' => $currency->id,
            'lines' => [['product_service_id' => $service->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->json('data.id');
        Sale::whereKey($saleId)->update(['status' => 'approved']);

        $payload = ['receipt_date' => '2026-08-15', 'amount' => 100, 'payment_method_id' => $method->id, 'cash_account_id' => $account->id];
        $response = $client->withHeader('Idempotency-Key', 'sales-paid-now-11b')->postJson('/api/v1/sales/'.$saleId.'/paid-now', $payload);
        $response->assertOk()->assertJsonPath('data.sale.status', 'posted')->assertJsonPath('data.sale.settlement_status', 'paid')->assertJsonPath('data.receipt.status', 'posted')->assertJsonPath('data.receipt.receipt_type', 'paid_now_sale_receipt');

        $receiptId = $response->json('data.receipt.id');
        $this->assertDatabaseHas('receivable_open_items', ['source_sale_id' => $saleId, 'remaining_amount' => '0.000000', 'settlement_status' => 'paid']);
        $this->assertDatabaseHas('payment_applications', ['receipt_id' => $receiptId, 'amount' => '100.000000', 'status' => 'fully_applied']);
        $this->assertSame(1, (int) $this->app->make('db')->table('cash_movements')->where('source_record_id', $receiptId)->where('movement_status', 'posted')->count());
        $this->assertSame(1, (int) $this->app->make('db')->table('receipts')->where('source_sale_id', $saleId)->where('receipt_type', 'paid_now_sale_receipt')->count());

        $client->withHeader('Idempotency-Key', 'sales-paid-now-11b')->postJson('/api/v1/sales/'.$saleId.'/paid-now', $payload)->assertOk();
        $this->assertSame(1, (int) $this->app->make('db')->table('cash_movements')->where('source_record_id', $receiptId)->where('movement_status', 'posted')->count());
        $this->assertSame(1, (int) $this->app->make('db')->table('payment_applications')->where('receipt_id', $receiptId)->count());
    }

    public function test_paid_now_failure_keeps_sale_approved_and_creates_no_partial_effects(): void
    {
        [$user, $company, $customer, $service, $currency, $account, $method, $term] = $this->context();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $saleId = $client->postJson('/api/v1/sales', ['sale_type' => 'cash_sale', 'payment_basis' => 'cash', 'sale_date' => '2026-08-15', 'customer_id' => $customer->id, 'currency_id' => $currency->id, 'lines' => [['product_service_id' => $service->id, 'quantity' => 1, 'unit_price' => 100]]])->assertCreated()->json('data.id');
        Sale::whereKey($saleId)->update(['status' => 'approved']);

        $client->withHeader('Idempotency-Key', 'sales-paid-now-failure')->postJson('/api/v1/sales/'.$saleId.'/paid-now', ['receipt_date' => '2026-08-15', 'amount' => 100, 'payment_method_id' => (string) Str::uuid(), 'cash_account_id' => (string) Str::uuid()])->assertStatus(409);
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'approved']);
        $this->assertDatabaseMissing('receivable_open_items', ['source_sale_id' => $saleId]);
        $this->assertDatabaseMissing('receipts', ['source_sale_id' => $saleId]);
    }

    public function test_paid_now_supports_partial_receipt_and_preserves_remaining_receivable(): void
    {
        [$user, $company, $customer, $service, $currency, $account, $method] = $this->context();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $saleId = $client->postJson('/api/v1/sales', ['sale_type' => 'cash_sale', 'payment_basis' => 'cash', 'sale_date' => '2026-08-15', 'customer_id' => $customer->id, 'currency_id' => $currency->id, 'lines' => [['product_service_id' => $service->id, 'quantity' => 1, 'unit_price' => 100]]])->assertCreated()->json('data.id');
        Sale::whereKey($saleId)->update(['status' => 'approved']);

        $response = $client->withHeader('Idempotency-Key', 'sales-paid-now-partial')->postJson('/api/v1/sales/'.$saleId.'/paid-now', ['receipt_date' => '2026-08-15', 'amount' => 40, 'payment_method_id' => $method->id, 'cash_account_id' => $account->id]);
        $response->assertOk()->assertJsonPath('data.sale.settlement_status', 'partially_paid')->assertJsonPath('data.sale.remaining_amount', '60.000000');
        $this->assertDatabaseHas('receivable_open_items', ['source_sale_id' => $saleId, 'remaining_amount' => '60.000000', 'settlement_status' => 'partially_paid']);
    }

    public function test_sales_dashboard_and_attention_sources_are_live_and_currency_scoped(): void
    {
        [$user, $company, $customer, $service, $currency, $account, $method, $term] = $this->context();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $saleId = $client->postJson('/api/v1/sales', ['sale_type' => 'credit_sale', 'payment_basis' => 'credit', 'sale_date' => '2026-08-15', 'customer_id' => $customer->id, 'currency_id' => $currency->id, 'payment_term_id' => $term->id, 'lines' => [['product_service_id' => $service->id, 'quantity' => 1, 'unit_price' => 100]]])->assertCreated()->json('data.id');

        $client->getJson('/api/v1/sales/dashboard')->assertOk()->assertJsonPath('data.metrics', []);
        $client->getJson('/api/v1/sales/attention')->assertOk()->assertJsonPath('data.total', 0);
        Sale::whereKey($saleId)->update(['status' => 'approved']);
        $client->postJson('/api/v1/sales/'.$saleId.'/post')->assertOk();
        $client->getJson('/api/v1/sales/dashboard')->assertOk()->assertJsonPath('data.metrics.0.currency.code', 'PHP')->assertJsonPath('data.metrics.0.sales_count', 1);
        Sale::whereKey($saleId)->update(['status' => 'failed']);
        $client->getJson('/api/v1/sales/attention')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.source_owner', 'MDS-200');
    }

    private function context(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Sales Paid Now', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = $user->companies()->firstOrFail();
        $currency = ReferenceCurrency::where('company_id', $company->id)->whereKey($company->default_currency_id)->firstOrFail();
        $customer = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CUS-11B', 'normalized_code' => 'cus-11b', 'party_type' => 'organization', 'official_name' => 'Paid Now Customer', 'display_name' => 'Paid Now Customer', 'status' => 'active', 'version' => 1]);
        BusinessPartnerRole::create(['id' => (string) Str::uuid(), 'business_partner_id' => $customer->id, 'company_id' => $company->id, 'role' => 'customer', 'status' => 'active', 'version' => 1]);
        $service = ProductService::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'SVC-11B', 'normalized_code' => 'svc-11b', 'name' => 'Paid-now service', 'record_type' => 'service', 'sellable' => true, 'purchasable' => false, 'stock_managed' => false, 'non_stock' => true, 'standard_selling_price' => 100, 'status' => 'active', 'version' => 1]);
        $term = PaymentTerm::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'DUE-11B', 'normalized_code' => 'due-11b', 'name' => 'Due on receipt', 'term_type' => 'due_days', 'due_days' => 0, 'status' => 'active', 'version' => 1]);
        $this->title($company, 'AR-11B', 'Accounts Receivable', 'asset', 'debit', 'receivable');
        $this->title($company, 'REV-11B', 'Sales Revenue', 'income', 'credit', 'revenue');
        $cashTitle = $this->title($company, 'CASH-11B', 'Cash on Hand', 'asset', 'debit', 'cash');
        $type = CashAccountType::where('code', 'CASH_BOX')->firstOrFail();
        $account = CashAccount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-11B', 'name' => 'Main Cash', 'display_name' => 'Main Cash', 'cash_account_type_id' => $type->id, 'account_title_id' => $cashTitle->id, 'currency_id' => $currency->id, 'status' => 'active', 'version' => 1]);
        CashAccountCapability::create(['id' => (string) Str::uuid(), 'cash_account_id' => $account->id, 'capability' => 'RECEIVE_FUNDS', 'enabled' => true, 'version' => 1]);
        $method = PaymentMethod::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-11B', 'normalized_code' => 'cash-11b', 'name' => 'Cash', 'method_class' => 'cash', 'supports_incoming' => true, 'supports_outgoing' => false, 'status' => 'active', 'clearing_behavior' => 'direct', 'version' => 1]);

        return [$user, $company, $customer, $service, $currency, $account, $method, $term];
    }

    private function title(Company $company, string $code, string $name, string $classification, string $normal, string $subtype): AccountTitle
    {
        return AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => $code, 'normalized_code' => strtolower($code), 'name' => $name, 'classification' => $classification, 'normal_balance' => $normal, 'account_subtype' => $subtype, 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
    }
}
