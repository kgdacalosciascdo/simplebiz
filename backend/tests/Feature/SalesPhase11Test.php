<?php

namespace Tests\Feature;

use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerRole;
use App\Models\Company;
use App\Models\PaymentTerm;
use App\Models\ProductService;
use App\Models\Sale;
use App\Models\SalesAdjustment;
use App\Models\SalesReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesPhase11Test extends TestCase
{
    use RefreshDatabase;

    public function test_sales_return_is_linked_quantity_safe_and_reduces_the_receivable(): void
    {
        [$user, $company, $customer, $service, $term] = $this->salesContext();
        $this->accountTitles($company);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $saleId = $this->postSale($client, $customer, $service, $term, 100);
        $saleLineId = Sale::findOrFail($saleId)->lines()->value('id');

        $return = $client->postJson('/api/v1/sales/returns', ['sale_id' => $saleId, 'return_date' => '2026-08-05', 'explanation' => 'Customer returned the service line.', 'lines' => [['sale_line_id' => $saleLineId, 'quantity' => 0.4]]]);
        $return->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_amount', '40.000000');
        $returnId = $return->json('data.id');
        SalesReturn::whereKey($returnId)->update(['status' => 'approved']);

        $posted = $client->postJson('/api/v1/sales/returns/'.$returnId.'/post');
        $posted->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.customer_credit_amount', '0.000000');
        $this->assertDatabaseHas('sales_receivable_effects', ['source_id' => $returnId, 'effect_type' => 'sales_return_posted', 'amount_delta' => '-40.000000']);
        $this->assertDatabaseHas('receivable_open_items', ['source_sale_id' => $saleId, 'remaining_amount' => '60.000000', 'return_amount' => '40.000000']);
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'return_status' => 'partially_returned', 'remaining_amount' => '60.000000']);

        $client->postJson('/api/v1/sales/returns/'.$returnId.'/reverse', ['reason' => 'Return was entered in error'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertDatabaseHas('receivable_open_items', ['source_sale_id' => $saleId, 'remaining_amount' => '100.000000', 'return_amount' => '0.000000']);
    }

    public function test_sales_return_cannot_over_return_a_sale_line(): void
    {
        [$user, $company, $customer, $service, $term] = $this->salesContext();
        $this->accountTitles($company);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $saleId = $this->postSale($client, $customer, $service, $term, 100);
        $saleLineId = Sale::findOrFail($saleId)->lines()->value('id');
        $client->postJson('/api/v1/sales/returns', ['sale_id' => $saleId, 'return_date' => '2026-08-05', 'explanation' => 'First return.', 'lines' => [['sale_line_id' => $saleLineId, 'quantity' => 0.75]]])->assertCreated();

        $client->postJson('/api/v1/sales/returns', ['sale_id' => $saleId, 'return_date' => '2026-08-05', 'explanation' => 'Too much returned.', 'lines' => [['sale_line_id' => $saleLineId, 'quantity' => 0.5]]])->assertStatus(409)->assertJsonPath('errors.dependency', 'returnable_quantity');
    }

    public function test_sales_credit_and_debit_adjustments_use_separate_effects_and_reversal(): void
    {
        [$user, $company, $customer, $service, $term] = $this->salesContext();
        $this->accountTitles($company);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $saleId = $this->postSale($client, $customer, $service, $term, 100);

        $credit = $client->postJson('/api/v1/sales/adjustments', ['sale_id' => $saleId, 'adjustment_type' => 'credit', 'adjustment_date' => '2026-08-05', 'explanation' => 'Approved service credit.', 'lines' => [['description' => 'Service correction', 'unit_amount' => 20, 'tax_amount' => 0]]]);
        $credit->assertCreated()->assertJsonPath('data.adjustment_type', 'credit');
        $creditId = $credit->json('data.id');
        SalesAdjustment::whereKey($creditId)->update(['status' => 'approved']);
        $client->postJson('/api/v1/sales/adjustments/'.$creditId.'/post')->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('receivable_open_items', ['source_sale_id' => $saleId, 'remaining_amount' => '80.000000', 'credit_adjustment_amount' => '20.000000']);

        $debit = $client->postJson('/api/v1/sales/adjustments', ['sale_id' => $saleId, 'adjustment_type' => 'debit', 'adjustment_date' => '2026-08-05', 'explanation' => 'Approved additional service.', 'lines' => [['description' => 'Additional work', 'unit_amount' => 10, 'tax_amount' => 0]]]);
        $debit->assertCreated();
        $debitId = $debit->json('data.id');
        SalesAdjustment::whereKey($debitId)->update(['status' => 'approved']);
        $client->postJson('/api/v1/sales/adjustments/'.$debitId.'/post')->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('receivable_open_items', ['source_sale_id' => $saleId, 'remaining_amount' => '90.000000', 'debit_adjustment_amount' => '10.000000', 'credit_adjustment_amount' => '20.000000']);

        $client->postJson('/api/v1/sales/adjustments/'.$creditId.'/reverse', ['reason' => 'Credit corrected'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertDatabaseHas('receivable_open_items', ['source_sale_id' => $saleId, 'remaining_amount' => '110.000000', 'credit_adjustment_amount' => '0.000000']);
    }

    private function postSale($client, BusinessPartner $customer, ProductService $service, PaymentTerm $term, int $amount): string
    {
        $response = $client->postJson('/api/v1/sales', ['sale_type' => 'credit_sale', 'payment_basis' => 'credit', 'sale_date' => '2026-08-04', 'customer_id' => $customer->id, 'payment_term_id' => $term->id, 'lines' => [['product_service_id' => $service->id, 'quantity' => 1, 'unit_price' => $amount, 'price_override_reason' => 'Phase 11 test']]]);
        $id = $response->assertCreated()->json('data.id');
        Sale::whereKey($id)->update(['status' => 'approved']);
        $client->postJson('/api/v1/sales/'.$id.'/post')->assertOk();

        return $id;
    }

    private function salesContext(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Sales Phase 11 Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
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
