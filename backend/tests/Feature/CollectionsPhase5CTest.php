<?php

namespace Tests\Feature;

use App\Events\CollectionsLifecycleEvent;
use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerRole;
use App\Models\CashAccount;
use App\Models\CashAccountCapability;
use App\Models\CashAccountType;
use App\Models\CashRemittance;
use App\Models\CashTransferDocument;
use App\Models\PaymentMethod;
use App\Models\ReasonCode;
use App\Models\Receipt;
use App\Models\ReferenceCurrency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionsPhase5CTest extends TestCase
{
    use RefreshDatabase;

    public function test_printable_receipt_is_authoritative_masked_and_reprint_is_non_financial(): void
    {
        Event::fake([CollectionsLifecycleEvent::class]);
        [$user, $company, $customer, $currency, $account, $method] = $this->context();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $receipt = $client->postJson('/api/v1/collections/receipts', [
            'receipt_type' => 'advance_unapplied_customer_receipt',
            'receipt_date' => '2026-08-14',
            'customer_id' => $customer->id,
            'currency_id' => $currency->id,
            'amount' => 75,
            'external_reference' => 'PUBLIC-REFERENCE-1234',
            'tenders' => [['payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'amount' => 75, 'external_reference' => 'BANK-REFERENCE-1234', 'instrument_reference' => 'CARD-SECRET-1234']],
        ])->assertCreated()->json('data');
        Receipt::whereKey($receipt['id'])->update(['status' => 'approved']);
        $client->postJson('/api/v1/collections/receipts/'.$receipt['id'].'/post')->assertOk();
        Event::assertDispatched(CollectionsLifecycleEvent::class, fn ($event) => $event->eventName === 'receipt.posted' && $event->documentId === $receipt['id']);

        $cashMovements = $this->app->make('db')->table('cash_movements')->where('source_record_id', $receipt['id'])->count();
        $accounting = $this->app->make('db')->table('accounting_transactions')->where('company_id', $company->id)->count();
        $print = $client->getJson('/api/v1/collections/receipts/'.$receipt['id'].'/print')->assertOk()->json('data');
        $this->assertSame($receipt['receipt_number'], $print['receipt_number']);
        $this->assertSame('75.000000', $print['unapplied_amount']);
        $this->assertSame('••••••••••••1234', $print['tenders'][0]['instrument_reference']);
        $this->assertStringNotContainsString('CARD-SECRET', json_encode($print, JSON_THROW_ON_ERROR));

        $reprint = $client->postJson('/api/v1/collections/receipts/'.$receipt['id'].'/reprint', ['reason' => 'Customer requested an additional copy', 'channel' => 'screen'])->assertOk()->json('data');
        $duplicate = $client->getJson('/api/v1/collections/receipts/'.$receipt['id'].'/print?reprint_id='.$reprint['id'])->assertOk()->json('data');
        $this->assertSame($receipt['receipt_number'], $duplicate['receipt_number']);
        $this->assertTrue($duplicate['copy']['is_reprint']);
        $this->assertSame($reprint['id'], $duplicate['copy']['reprint_id']);
        $this->assertSame($cashMovements, $this->app->make('db')->table('cash_movements')->where('source_record_id', $receipt['id'])->count());
        $this->assertSame($accounting, $this->app->make('db')->table('accounting_transactions')->where('company_id', $company->id)->count());
        Event::assertDispatched(CollectionsLifecycleEvent::class, fn ($event) => $event->eventName === 'receipt.reprinted' && $event->documentId === $receipt['id']);
    }

    public function test_accepted_remittance_posts_one_mds700_transfer_without_second_customer_effect(): void
    {
        [$user, $company, $customer, $currency, $source, $method] = $this->context();
        $preparedBy = User::factory()->create(['name' => 'Cashier']);
        $destination = $this->account($company, $currency, 'BANK-5C', 'Remittance bank', ['TRANSFER_IN']);
        $source->capabilities()->create(['id' => (string) Str::uuid(), 'capability' => 'TRANSFER_OUT', 'enabled' => true, 'version' => 1]);
        ReasonCode::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'TRANSFER-5C', 'normalized_code' => 'transfer-5c', 'name' => 'Remittance transfer', 'domain' => 'TRANSFER', 'status' => 'active', 'version' => 1]);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $receipt = $client->postJson('/api/v1/collections/receipts', ['receipt_type' => 'advance_unapplied_customer_receipt', 'receipt_date' => '2026-08-14', 'customer_id' => $customer->id, 'currency_id' => $currency->id, 'amount' => 80, 'tenders' => [['payment_method_id' => $method->id, 'cash_account_id' => $source->id, 'amount' => 80]]])->assertCreated()->json('data');
        Receipt::whereKey($receipt['id'])->update(['status' => 'approved']);
        $client->postJson('/api/v1/collections/receipts/'.$receipt['id'].'/post')->assertOk();
        $tender = Receipt::findOrFail($receipt['id'])->tenders()->firstOrFail();
        $remittance = $client->postJson('/api/v1/collections/remittances', ['currency_id' => $currency->id, 'destination_cash_account_id' => $destination->id, 'remittance_date' => '2026-08-14', 'tender_ids' => [$tender->id]])->assertCreated()->json('data');
        CashRemittance::whereKey($remittance['id'])->update(['status' => 'verified', 'prepared_by' => $preparedBy->id, 'verified_by' => $preparedBy->id, 'verified_at' => now()]);
        $receiptCount = Receipt::where('company_id', $company->id)->count();
        $response = $client->postJson('/api/v1/collections/remittances/'.$remittance['id'].'/accept')->assertOk()->json('data');
        $this->assertSame('accepted', $response['status']);
        $this->assertSame('posted', $response['transfer']['status']);
        $this->assertSame(1, CashTransferDocument::where('company_id', $company->id)->count());
        $this->assertSame(2, $this->app->make('db')->table('cash_transfer_legs')->count());
        $this->assertSame(2, $this->app->make('db')->table('cash_movements')->where('source_record_type', CashTransferDocument::class)->count());
        $this->assertSame('remitted', $tender->fresh()->remittance_status);
        $this->assertSame($receiptCount, Receipt::where('company_id', $company->id)->count());
        $this->assertSame('80.000000', Receipt::findOrFail($receipt['id'])->fresh()->unapplied_amount);

        $client->postJson('/api/v1/collections/remittances/'.$remittance['id'].'/reverse', ['reason' => 'Deposit was returned to the cashier'])->assertOk();
        $this->assertDatabaseHas('cash_remittances', ['id' => $remittance['id'], 'status' => 'reversed']);
        $this->assertDatabaseHas('cash_transfer_documents', ['id' => $response['cash_transfer_document_id'], 'status' => 'reversed']);
        $this->assertSame('unremitted', $tender->fresh()->remittance_status);
        $this->assertSame(2, CashTransferDocument::where('company_id', $company->id)->count());
    }

    public function test_remittance_transfer_failure_rolls_back_without_remitting_tender(): void
    {
        [$user, $company, $customer, $currency, $source, $method] = $this->context();
        $preparedBy = User::factory()->create(['name' => 'Cashier']);
        $destination = $this->account($company, $currency, 'NO-IN-5C', 'Unavailable destination', []);
        $source->capabilities()->create(['id' => (string) Str::uuid(), 'capability' => 'TRANSFER_OUT', 'enabled' => true, 'version' => 1]);
        ReasonCode::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'TRANSFER-FAIL-5C', 'normalized_code' => 'transfer-fail-5c', 'name' => 'Remittance transfer failure', 'domain' => 'TRANSFER', 'status' => 'active', 'version' => 1]);
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $receipt = $client->postJson('/api/v1/collections/receipts', ['receipt_type' => 'advance_unapplied_customer_receipt', 'receipt_date' => '2026-08-14', 'customer_id' => $customer->id, 'currency_id' => $currency->id, 'amount' => 20, 'tenders' => [['payment_method_id' => $method->id, 'cash_account_id' => $source->id, 'amount' => 20]]])->assertCreated()->json('data');
        Receipt::whereKey($receipt['id'])->update(['status' => 'approved']);
        $client->postJson('/api/v1/collections/receipts/'.$receipt['id'].'/post')->assertOk();
        $tender = Receipt::findOrFail($receipt['id'])->tenders()->firstOrFail();
        $remittance = $client->postJson('/api/v1/collections/remittances', ['currency_id' => $currency->id, 'destination_cash_account_id' => $destination->id, 'remittance_date' => '2026-08-14', 'tender_ids' => [$tender->id]])->assertCreated()->json('data');
        CashRemittance::whereKey($remittance['id'])->update(['status' => 'verified', 'prepared_by' => $preparedBy->id, 'verified_by' => $preparedBy->id]);
        $client->postJson('/api/v1/collections/remittances/'.$remittance['id'].'/accept')->assertStatus(409);
        $this->assertDatabaseHas('cash_remittances', ['id' => $remittance['id'], 'status' => 'verified', 'cash_transfer_document_id' => null]);
        $this->assertSame('unremitted', $tender->fresh()->remittance_status);
        $this->assertSame(0, CashTransferDocument::where('company_id', $company->id)->count());
    }

    private function context(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'phase5c-'.Str::random(8).'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Phase 5C Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = $user->companies()->firstOrFail();
        $currency = ReferenceCurrency::where('company_id', $company->id)->firstOrFail();
        $customer = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CUS-5C', 'normalized_code' => 'cus-5c', 'party_type' => 'organization', 'official_name' => 'Phase 5C Customer', 'display_name' => 'Phase 5C Customer', 'status' => 'active', 'version' => 1]);
        BusinessPartnerRole::create(['id' => (string) Str::uuid(), 'business_partner_id' => $customer->id, 'company_id' => $company->id, 'role' => 'customer', 'status' => 'active', 'version' => 1]);
        $cashTitle = $this->title($company, 'CASH-5C', 'Cash on Hand', 'asset', 'cash');
        $this->title($company, 'AR-5C', 'Accounts Receivable', 'asset', 'receivable');
        $this->title($company, 'ADV-5C', 'Customer Advances', 'liability', 'advance');
        $type = CashAccountType::where('code', 'CASH_BOX')->firstOrFail();
        $account = CashAccount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-5C', 'name' => 'Phase 5C Cash', 'display_name' => 'Phase 5C Cash', 'cash_account_type_id' => $type->id, 'account_title_id' => $cashTitle->id, 'currency_id' => $currency->id, 'status' => 'active', 'version' => 1]);
        foreach (['RECEIVE_FUNDS'] as $capability) {
            CashAccountCapability::create(['id' => (string) Str::uuid(), 'cash_account_id' => $account->id, 'capability' => $capability, 'enabled' => true, 'version' => 1]);
        }
        $method = PaymentMethod::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-5C', 'normalized_code' => 'cash-5c', 'name' => 'Cash', 'method_class' => 'cash', 'supports_incoming' => true, 'supports_outgoing' => false, 'status' => 'active', 'clearing_behavior' => 'direct', 'version' => 1]);

        return [$user, $company, $customer, $currency, $account, $method];
    }

    private function account($company, $currency, string $code, string $name, array $capabilities): CashAccount
    {
        $type = CashAccountType::where('code', 'BANK_ACCOUNT')->first() ?? CashAccountType::where('code', 'CASH_BOX')->firstOrFail();
        $title = AccountTitle::where('company_id', $company->id)->where('account_subtype', 'cash')->firstOrFail();
        $account = CashAccount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => $code, 'name' => $name, 'display_name' => $name, 'cash_account_type_id' => $type->id, 'account_title_id' => $title->id, 'currency_id' => $currency->id, 'status' => 'active', 'version' => 1]);
        foreach ($capabilities as $capability) {
            CashAccountCapability::create(['id' => (string) Str::uuid(), 'cash_account_id' => $account->id, 'capability' => $capability, 'enabled' => true, 'version' => 1]);
        }

        return $account;
    }

    private function title($company, string $code, string $name, string $classification, string $subtype): AccountTitle
    {
        return AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => $code, 'normalized_code' => strtolower($code), 'name' => $name, 'classification' => $classification, 'normal_balance' => in_array($classification, ['liability', 'equity', 'income'], true) ? 'credit' : 'debit', 'account_subtype' => $subtype, 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
    }
}
