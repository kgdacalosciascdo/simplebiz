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
use App\Models\Receipt;
use App\Models\ReferenceCurrency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionsPhase5BTest extends TestCase
{
    use RefreshDatabase;

    public function test_other_receipt_is_controlled_posted_and_reprint_is_audit_only(): void
    {
        [$user, $company, $currency, $account, $method] = $this->context();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $draft = $client->postJson('/api/v1/collections/other-receipts', ['other_receipt_type' => 'INTEREST_INCOME', 'receipt_date' => '2026-08-14', 'currency_id' => $currency->id, 'amount' => 25, 'counterparty_name' => 'Bank', 'business_purpose' => 'Interest earned on operating account', 'source_reference' => 'BANK-INT-001', 'evidence_reference' => 'bank-statement-aug', 'tenders' => [['payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'amount' => 25]]])->assertCreated()->json('data');
        Receipt::whereKey($draft['id'])->update(['status' => 'approved']);
        $client->postJson('/api/v1/collections/receipts/'.$draft['id'].'/post')->assertOk()->assertJsonPath('data.receipt_type', 'other_receipt');
        $client->postJson('/api/v1/collections/receipts/'.$draft['id'].'/reprint', ['reason' => 'Customer requested a copy', 'channel' => 'screen'])->assertOk();
        $this->assertDatabaseHas('receipt_reprints', ['receipt_id' => $draft['id'], 'channel' => 'screen']);
        $this->assertDatabaseHas('cash_movements', ['source_record_id' => $draft['id'], 'direction' => 'increase']);
    }

    public function test_collection_activity_remittance_and_report_are_available(): void
    {
        [$user, $company, $customer, $currency, $account, $method] = $this->context(true);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $receipt = $client->postJson('/api/v1/collections/receipts', ['receipt_type' => 'advance_unapplied_customer_receipt', 'receipt_date' => '2026-08-14', 'customer_id' => $customer->id, 'currency_id' => $currency->id, 'amount' => 40, 'tenders' => [['payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'amount' => 40]]])->assertCreated()->json('data');
        Receipt::whereKey($receipt['id'])->update(['status' => 'approved']);
        $client->postJson('/api/v1/collections/receipts/'.$receipt['id'].'/post')->assertOk();
        $tender = Receipt::findOrFail($receipt['id'])->tenders()->first();
        $client->postJson('/api/v1/collections/activities', ['customer_id' => $customer->id, 'activity_type' => 'follow_up', 'status' => 'due', 'notes' => 'Follow up on advance allocation'])->assertCreated();
        $remittance = $client->postJson('/api/v1/collections/remittances', ['currency_id' => $currency->id, 'remittance_date' => '2026-08-14', 'tender_ids' => [$tender->id]])->assertCreated()->json('data');
        $client->postJson('/api/v1/collections/remittances/'.$remittance['id'].'/submit')->assertOk();
        $this->assertSame(200, $client->getJson('/api/v1/collections/reports/unapplied')->status());
        $this->assertDatabaseHas('collection_activities', ['customer_id' => $customer->id, 'status' => 'due']);
    }

    public function test_failed_tender_creates_a_governed_reversal_without_leaving_cash_settled(): void
    {
        [$user, $company, $customer, $currency, $account, $method] = $this->context(true);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $receipt = $client->postJson('/api/v1/collections/receipts', ['receipt_type' => 'advance_unapplied_customer_receipt', 'receipt_date' => '2026-08-14', 'customer_id' => $customer->id, 'currency_id' => $currency->id, 'amount' => 30, 'tenders' => [['payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'amount' => 30]]])->assertCreated()->json('data');
        Receipt::whereKey($receipt['id'])->update(['status' => 'approved']);
        $client->postJson('/api/v1/collections/receipts/'.$receipt['id'].'/post')->assertOk();
        $tender = Receipt::findOrFail($receipt['id'])->tenders()->first();
        $client->postJson('/api/v1/collections/tenders/'.$tender->id.'/fail', ['reason' => 'Bank returned the instrument'])->assertOk();
        $this->assertDatabaseHas('receipt_tenders', ['id' => $tender->id, 'instrument_status' => 'failed']);
        $this->assertDatabaseHas('receipts', ['id' => $receipt['id'], 'status' => 'failed']);
        $this->assertSame(2, (int) $this->app->make('db')->table('cash_movements')->where('source_record_id', $receipt['id'])->count());
    }

    private function context(bool $customerNeeded = false): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'phase5b@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Phase 5B Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = $user->companies()->firstOrFail();
        $currency = ReferenceCurrency::where('company_id', $company->id)->firstOrFail();
        $customer = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CUS-5B', 'normalized_code' => 'cus-5b', 'party_type' => 'organization', 'official_name' => 'Phase 5B Customer', 'display_name' => 'Phase 5B Customer', 'status' => 'active', 'version' => 1]);
        BusinessPartnerRole::create(['id' => (string) Str::uuid(), 'business_partner_id' => $customer->id, 'company_id' => $company->id, 'role' => 'customer', 'status' => 'active', 'version' => 1]);
        $cashTitle = $this->title($company, 'CASH-5B', 'Cash on Hand', 'asset', 'cash');
        $this->title($company, 'AR-5B', 'Accounts Receivable', 'asset', 'receivable');
        $this->title($company, 'ADV-5B', 'Customer Advances', 'liability', 'advance');
        $this->title($company, 'INT-5B', 'Interest Income', 'income', 'interest');
        $type = CashAccountType::where('code', 'CASH_BOX')->firstOrFail();
        $account = CashAccount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-5B', 'name' => 'Phase 5B Cash', 'display_name' => 'Phase 5B Cash', 'cash_account_type_id' => $type->id, 'account_title_id' => $cashTitle->id, 'currency_id' => $currency->id, 'status' => 'active', 'version' => 1]);
        CashAccountCapability::create(['id' => (string) Str::uuid(), 'cash_account_id' => $account->id, 'capability' => 'RECEIVE_FUNDS', 'enabled' => true, 'version' => 1]);
        $method = PaymentMethod::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-5B', 'normalized_code' => 'cash-5b', 'name' => 'Cash', 'method_class' => 'cash', 'supports_incoming' => true, 'supports_outgoing' => false, 'status' => 'active', 'clearing_behavior' => 'direct', 'version' => 1]);

        return $customerNeeded ? [$user, $company, $customer, $currency, $account, $method] : [$user, $company, $currency, $account, $method];
    }

    private function title(Company $company, string $code, string $name, string $classification, string $subtype): AccountTitle
    {
        return AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => $code, 'normalized_code' => strtolower($code), 'name' => $name, 'classification' => $classification, 'normal_balance' => in_array($classification, ['liability', 'equity', 'income'], true) ? 'credit' : 'debit', 'account_subtype' => $subtype, 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
    }
}
