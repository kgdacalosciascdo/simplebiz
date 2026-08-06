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
use App\Models\Receipt;
use App\Models\ReceivableOpenItem;
use App\Models\ReferenceCurrency;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionsPhase5ATest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_posting_applies_to_mds200_and_creates_mds700_effect(): void
    {
        [$user, $company, $customer, $currency, $account, $method] = $this->context();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $sale = Sale::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'sale_number' => 'SAL-TEST', 'sale_type' => 'credit_sale', 'payment_basis' => 'credit', 'sale_date' => '2026-08-04', 'customer_id' => $customer->id, 'currency_id' => $currency->id, 'payment_term_id' => $this->term($company)->id, 'status' => 'posted', 'settlement_status' => 'unpaid', 'due_status' => 'no_due_date', 'dispute_status' => 'not_disputed', 'subtotal' => 100, 'taxable_amount' => 100, 'total' => 100, 'receivable_amount' => 100, 'remaining_amount' => 100, 'version' => 1]);
        $saleId = $sale->id;
        $openItem = ReceivableOpenItem::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'customer_id' => $customer->id, 'source_sale_id' => $saleId, 'source_document_number' => $sale->sale_number, 'currency_id' => $currency->id, 'original_amount' => 100, 'remaining_amount' => 100, 'due_status' => 'no_due_date', 'settlement_status' => 'unpaid', 'dispute_status' => 'not_disputed', 'version' => 1]);
        $receipt = $client->postJson('/api/v1/collections/receipts', ['receipt_type' => 'customer_collection', 'receipt_date' => '2026-08-04', 'customer_id' => $customer->id, 'currency_id' => $currency->id, 'amount' => 100, 'tenders' => [['payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'amount' => 100]], 'applications' => [['receivable_open_item_id' => $openItem->id, 'amount' => 100]]])->assertCreated()->json('data');
        Receipt::whereKey($receipt['id'])->update(['status' => 'approved']);
        $client->withHeader('Idempotency-Key', 'receipt-post-1')->postJson('/api/v1/collections/receipts/'.$receipt['id'].'/post')->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseHas('receivable_open_items', ['source_sale_id' => $saleId, 'remaining_amount' => '0.000000', 'settlement_status' => 'paid']);
        $this->assertDatabaseHas('cash_movements', ['source_record_id' => $receipt['id'], 'movement_status' => 'posted', 'direction' => 'increase']);
        $this->assertDatabaseHas('business_transactions', ['transaction_type' => 'customer_receipt']);
    }

    public function test_unapplied_receipt_can_be_applied_later_without_new_cash_effect(): void
    {
        [$user, $company, $customer, $currency, $account, $method] = $this->context();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $receipt = $client->postJson('/api/v1/collections/receipts', ['receipt_type' => 'advance_unapplied_customer_receipt', 'receipt_date' => '2026-08-04', 'customer_id' => $customer->id, 'currency_id' => $currency->id, 'amount' => 75, 'tenders' => [['payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'amount' => 75]]])->assertCreated()->json('data');
        Receipt::whereKey($receipt['id'])->update(['status' => 'approved']);
        $client->withHeader('Idempotency-Key', 'receipt-post-2')->postJson('/api/v1/collections/receipts/'.$receipt['id'].'/post')->assertOk();
        $this->assertDatabaseHas('customer_unapplied_receipts', ['receipt_id' => $receipt['id'], 'available_amount' => '75.000000']);
        $this->assertSame(1, (int) $this->app->make('db')->table('cash_movements')->where('source_record_id', $receipt['id'])->count());
        $client->withHeader('Idempotency-Key', 'receipt-reverse-2')->postJson('/api/v1/collections/receipts/'.$receipt['id'].'/reverse', ['reason' => 'Customer payment was returned before application'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame(2, (int) $this->app->make('db')->table('cash_movements')->where('source_record_id', $receipt['id'])->count());
        $this->assertDatabaseHas('customer_unapplied_receipts', ['receipt_id' => $receipt['id'], 'status' => 'reversed', 'available_amount' => '0.000000']);
    }

    private function context(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Collections Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = $user->companies()->firstOrFail();
        $currency = ReferenceCurrency::where('company_id', $company->id)->firstOrFail();
        $customer = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CUS-001', 'normalized_code' => 'cus-001', 'party_type' => 'organization', 'official_name' => 'Customer One', 'display_name' => 'Customer One', 'status' => 'active', 'version' => 1]);
        BusinessPartnerRole::create(['id' => (string) Str::uuid(), 'business_partner_id' => $customer->id, 'company_id' => $company->id, 'role' => 'customer', 'status' => 'active', 'version' => 1]);
        $cashTitle = $this->title($company, 'CASH-001', 'Cash on Hand', 'asset', 'debit', 'cash');
        $this->title($company, 'AR-001', 'Accounts Receivable', 'asset', 'debit', 'receivable');
        $this->title($company, 'ADV-001', 'Customer Advances', 'liability', 'credit', 'advance');
        $type = CashAccountType::where('code', 'CASH_BOX')->firstOrFail();
        $account = CashAccount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-001', 'name' => 'Main Cash', 'display_name' => 'Main Cash', 'cash_account_type_id' => $type->id, 'account_title_id' => $cashTitle->id, 'currency_id' => $currency->id, 'status' => 'active', 'version' => 1]);
        CashAccountCapability::create(['id' => (string) Str::uuid(), 'cash_account_id' => $account->id, 'capability' => 'RECEIVE_FUNDS', 'enabled' => true, 'version' => 1]);
        $method = PaymentMethod::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH', 'normalized_code' => 'cash', 'name' => 'Cash', 'method_class' => 'cash', 'supports_incoming' => true, 'supports_outgoing' => false, 'status' => 'active', 'clearing_behavior' => 'direct', 'version' => 1]);

        return [$user, $company, $customer, $currency, $account, $method];
    }

    private function title(Company $company, string $code, string $name, string $classification, string $normal, string $subtype): AccountTitle
    {
        return AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => $code, 'normalized_code' => strtolower($code), 'name' => $name, 'classification' => $classification, 'normal_balance' => $normal, 'account_subtype' => $subtype, 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
    }

    private function term(Company $company): PaymentTerm
    {
        return PaymentTerm::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'NET-30', 'normalized_code' => 'net-30', 'name' => 'Net 30', 'term_type' => 'due_days', 'due_days' => 30, 'status' => 'active', 'version' => 1]);
    }

    private function service(Company $company): ProductService
    {
        return ProductService::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'SVC-001', 'normalized_code' => 'svc-001', 'name' => 'Consulting', 'record_type' => 'service', 'sellable' => true, 'purchasable' => false, 'stock_managed' => false, 'non_stock' => true, 'standard_selling_price' => 100, 'status' => 'active', 'version' => 1]);
    }
}
