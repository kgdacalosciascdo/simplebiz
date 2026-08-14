<?php

namespace Tests\Feature;

use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerRole;
use App\Models\CashAccount;
use App\Models\CashAccountCapability;
use App\Models\CashAccountType;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\PayableOpenItem;
use App\Models\PaymentInstrument;
use App\Models\PaymentMethod;
use App\Models\ReferenceCurrency;
use App\Models\Role;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentsPhase8BTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_payment_reversal_restores_payable_and_reverses_cash_once(): void
    {
        [$owner, $company, $supplier, $currency, $method, $cashAccount, $payable] = $this->context();
        $client = $this->client($owner, $company);
        $paymentId = $this->completePayment($client, $supplier, $currency, $method, $cashAccount, $payable, '75.00', '8B-REV');
        $client->postJson('/api/v1/payments/'.$paymentId.'/allocate', ['payable_open_item_id' => $payable->id, 'amount' => '75.00', 'allocation_date' => now()->toDateString()])->assertOk();
        $client->postJson('/api/v1/payments/'.$paymentId.'/voucher')->assertCreated()->assertJsonPath('data.voucher_number', 'DVO-000001');
        $client->postJson('/api/v1/payments/'.$paymentId.'/voucher?reprint=1')->assertOk()->assertJsonPath('data.reprint_count', 1);

        $client->postJson('/api/v1/payments/'.$paymentId.'/reverse', ['reason' => 'Duplicate supplier settlement identified.', 'evidence_reference' => 'case-8b-reversal'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertDatabaseCount('cash_movements', 3);
        $this->assertDatabaseHas('payment_corrections', ['original_payment_id' => $paymentId, 'correction_type' => 'reversal', 'status' => 'completed']);
        $this->assertDatabaseHas('payment_status_histories', ['payment_instruction_id' => $paymentId, 'from_status' => 'allocated', 'to_status' => 'reversed']);
        $this->assertSame('100.000000', (string) $payable->fresh()->remaining_amount);
        $this->assertSame('0.000000', (string) $payable->fresh()->paid_amount);
        $client->postJson('/api/v1/payments/'.$paymentId.'/reverse', ['reason' => 'Duplicate attempt'])->assertStatus(409);
    }

    public function test_unapply_and_reallocate_preserve_cash_and_source_history(): void
    {
        [$owner, $company, $supplier, $currency, $method, $cashAccount, $first] = $this->context();
        $second = $this->payable($company, $supplier, $currency, 'SINV-8B-002', '100.00');
        $client = $this->client($owner, $company);
        $paymentId = $this->completePayment($client, $supplier, $currency, $method, $cashAccount, $first, '75.00', '8B-ALLOC');
        $allocation = $client->postJson('/api/v1/payments/'.$paymentId.'/allocate', ['payable_open_item_id' => $first->id, 'amount' => '75.00', 'allocation_date' => now()->toDateString()])->assertOk();
        $allocationId = $allocation->json('data.allocations.0.id');
        $client->postJson('/api/v1/payments/'.$paymentId.'/allocations/'.$allocationId.'/reallocate', ['payable_open_item_id' => $second->id, 'amount' => '75.00', 'reason' => 'Apply to the corrected supplier document.'])->assertOk()->assertJsonPath('data.status', 'allocated');

        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertSame('100.000000', (string) $first->fresh()->remaining_amount);
        $this->assertSame('25.000000', (string) $second->fresh()->remaining_amount);
        $this->assertDatabaseHas('payment_allocations', ['parent_allocation_id' => $allocationId, 'correction_type' => 'reallocation', 'status' => 'unapplied']);
    }

    public function test_supplier_advance_is_confirmed_without_fake_invoice_and_can_be_applied(): void
    {
        [$owner, $company, $supplier, $currency, $method, $cashAccount, $payable] = $this->context();
        AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'ADV-8B', 'normalized_code' => 'adv-8b', 'name' => 'Supplier Advances', 'classification' => 'asset', 'normal_balance' => 'debit', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        $client = $this->client($owner, $company);
        $created = $client->postJson('/api/v1/payments/advances', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'payment_method_id' => $method->id, 'cash_account_id' => $cashAccount->id, 'amount' => '40.00', 'payment_date' => now()->toDateString(), 'reason' => 'Supplier prepayment'])->assertCreated();
        $paymentId = $created->json('data.id');
        $client->postJson('/api/v1/payments/'.$paymentId.'/submit')->assertOk();
        $client->postJson('/api/v1/payments/'.$paymentId.'/approve')->assertOk();
        $client->postJson('/api/v1/payments/'.$paymentId.'/release')->assertOk();
        $client->postJson('/api/v1/payments/'.$paymentId.'/confirm-cash', ['confirmed_amount' => '40.00', 'confirmed_date' => now()->toDateString(), 'recipient_acknowledgement' => 'Supplier acknowledged advance.', 'evidence_reference' => 'advance-8b'])->assertOk()->assertJsonPath('data.source_kind', 'supplier_advance');
        $advanceId = $created->json('data.advance.id');
        $client->postJson('/api/v1/payments/advances/'.$advanceId.'/apply', ['payable_open_item_id' => $payable->id, 'amount' => '40.00', 'reason' => 'Apply the supplier advance to the invoice.'])->assertOk();
        $this->assertSame('60.000000', (string) $payable->fresh()->remaining_amount);
        $this->assertDatabaseCount('supplier_invoices', 1);
        $this->assertDatabaseHas('payment_advances', ['id' => $advanceId, 'available_amount' => '0.000000', 'status' => 'applied']);
    }

    public function test_check_controls_preserve_number_and_link_replacement(): void
    {
        [$owner, $company, $supplier, $currency, $method, $cashAccount, $payable] = $this->context();
        $cashAccount->capabilities()->create(['id' => (string) Str::uuid(), 'capability' => 'ISSUE_CHECK', 'enabled' => true, 'version' => 1]);
        $checkMethod = PaymentMethod::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CHECK-8B', 'normalized_code' => 'check-8b', 'name' => 'Check Payment', 'method_class' => 'CHECK', 'supports_outgoing' => true, 'supports_incoming' => false, 'clearing_behavior' => 'pending', 'status' => 'active', 'version' => 1]);
        $client = $this->client($owner, $company);
        $paymentId = $this->completePayment($client, $supplier, $currency, $checkMethod, $cashAccount, $payable, '20.00', '8B-CHECK', false);
        $check = PaymentInstrument::where('payment_instruction_id', $paymentId)->firstOrFail();
        $client->postJson('/api/v1/payments/checks/'.$check->id.'/print')->assertOk();
        $client->postJson('/api/v1/payments/checks/'.$check->id.'/sign')->assertOk();
        $client->postJson('/api/v1/payments/checks/'.$check->id.'/stop', ['reason' => 'Lost check reported.', 'evidence_reference' => 'stop-8b'])->assertOk()->assertJsonPath('data.status', 'stopped');
        $replacement = $client->postJson('/api/v1/payments/checks/'.$check->id.'/replace', ['reason' => 'Issue replacement check.'])->assertOk();
        $replacementId = $replacement->json('data.replaced_by_instrument_id');
        $this->assertNotSame($check->check_number, PaymentInstrument::findOrFail($replacementId)->check_number);
        $this->assertDatabaseHas('payment_instruments', ['id' => $replacementId, 'replaces_instrument_id' => $check->id, 'status' => 'reserved']);
    }

    public function test_pending_execution_requires_evidenced_controlled_recovery(): void
    {
        [$owner, $company, $supplier, $currency, $method, $cashAccount, $payable] = $this->context();
        $client = $this->client($owner, $company);
        $paymentId = $this->completePayment($client, $supplier, $currency, $method, $cashAccount, $payable, '10.00', '8B-RECOVERY', false, true);
        $client->postJson('/api/v1/payments/'.$paymentId.'/recover', ['resolution' => 'retry', 'reason' => 'Bank status was unknown after timeout.', 'evidence_reference' => 'bank-case-8b'])->assertOk()->assertJsonPath('data.status', 'released');
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseCount('payment_execution_attempts', 2);
    }

    public function test_payment_batch_keeps_item_traceability_and_supports_partial_safe_execution(): void
    {
        [$owner, $company, $supplier, $currency, $method, $cashAccount, $first] = $this->context();
        $second = $this->payable($company, $supplier, $currency, 'SINV-8B-003', '100.00');
        $client = $this->client($owner, $company);
        $firstPayment = $this->preparePayment($client, $supplier, $currency, $method, $cashAccount, $first, '10.00', '8B-BATCH-1');
        $secondPayment = $this->preparePayment($client, $supplier, $currency, $method, $cashAccount, $second, '20.00', '8B-BATCH-2');
        $batch = $client->postJson('/api/v1/payments/batches', ['name' => 'Friday supplier run', 'payment_ids' => [$firstPayment, $secondPayment]])->assertCreated()->assertJsonPath('data.item_count', 2);
        $batchId = $batch->json('data.id');
        $client->postJson('/api/v1/payments/batches/'.$batchId.'/submit')->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $reviewer = User::factory()->create(['name' => 'Payment Reviewer', 'email' => 'reviewer-8b@example.test', 'status' => 'active']);
        $reviewer->companies()->attach($company->id, ['status' => 'active', 'is_owner' => false]);
        $role = Role::where('company_id', $company->id)->where('system_key', 'administrator')->firstOrFail();
        $reviewer->roles()->attach($role->id, ['company_id' => $company->id]);
        $reviewClient = $this->client($reviewer, $company);
        $reviewClient->postJson('/api/v1/payments/batches/'.$batchId.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $reviewClient->postJson('/api/v1/payments/batches/'.$batchId.'/generate')->assertOk()->assertJsonPath('data.status', 'generated');
        $reviewClient->postJson('/api/v1/payments/batches/'.$batchId.'/release')->assertOk()->assertJsonPath('data.status', 'completed');
        $reviewClient->postJson('/api/v1/payments/batches/'.$batchId.'/close')->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertDatabaseCount('payment_batch_items', 2);
        $this->assertDatabaseCount('payment_execution_attempts', 2);
        $this->assertDatabaseHas('payment_batch_items', ['payment_instruction_id' => $firstPayment, 'status' => 'succeeded']);
        $this->assertDatabaseHas('payment_batch_items', ['payment_instruction_id' => $secondPayment, 'status' => 'succeeded']);
    }

    private function completePayment($client, BusinessPartner $supplier, ReferenceCurrency $currency, PaymentMethod $method, CashAccount $cashAccount, PayableOpenItem $payable, string $amount, string $reference, bool $confirm = true, bool $pending = false): string
    {
        $paymentId = $client->postJson('/api/v1/payments', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'payment_method_id' => $method->id, 'cash_account_id' => $cashAccount->id, 'payment_date' => now()->toDateString(), 'reference' => $reference, 'sources' => [['payable_open_item_id' => $payable->id, 'amount' => $amount]]])->assertCreated()->json('data.id');
        $client->postJson('/api/v1/payments/'.$paymentId.'/submit')->assertOk();
        $client->postJson('/api/v1/payments/'.$paymentId.'/approve')->assertOk();
        $client->postJson('/api/v1/payments/'.$paymentId.'/release')->assertOk();
        if ($pending) {
            $client->postJson('/api/v1/payments/'.$paymentId.'/pending', ['response_message' => 'Unknown bank result'])->assertOk();
        } elseif ($confirm) {
            $client->postJson('/api/v1/payments/'.$paymentId.'/confirm-cash', ['confirmed_amount' => $amount, 'confirmed_date' => now()->toDateString(), 'recipient_acknowledgement' => 'Acknowledged.', 'evidence_reference' => 'receipt-'.$reference])->assertOk();
        }

        return $paymentId;
    }

    private function preparePayment($client, BusinessPartner $supplier, ReferenceCurrency $currency, PaymentMethod $method, CashAccount $cashAccount, PayableOpenItem $payable, string $amount, string $reference): string
    {
        $paymentId = $client->postJson('/api/v1/payments', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'payment_method_id' => $method->id, 'cash_account_id' => $cashAccount->id, 'payment_date' => now()->toDateString(), 'reference' => $reference, 'sources' => [['payable_open_item_id' => $payable->id, 'amount' => $amount]]])->assertCreated()->json('data.id');
        $client->postJson('/api/v1/payments/'.$paymentId.'/submit')->assertOk();
        $client->postJson('/api/v1/payments/'.$paymentId.'/approve')->assertOk();

        return $paymentId;
    }

    private function context(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Phase 8B Owner', 'email' => 'payments-8b-'.Str::lower(Str::random(8)).'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Payments 8B Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));
        $company = $owner->companies()->firstOrFail();
        $currency = ReferenceCurrency::where('company_id', $company->id)->where('code', 'PHP')->firstOrFail();
        $supplier = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'SUP-8B', 'normalized_code' => 'sup-8b', 'party_type' => 'organization', 'official_name' => 'Phase 8B Supplier', 'display_name' => 'Phase 8B Supplier', 'status' => 'active', 'version' => 1]);
        BusinessPartnerRole::create(['id' => (string) Str::uuid(), 'business_partner_id' => $supplier->id, 'company_id' => $company->id, 'role' => 'supplier', 'status' => 'active', 'version' => 1]);
        AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-8B', 'normalized_code' => 'cash-8b', 'name' => 'Cash Account 8B', 'classification' => 'asset', 'normal_balance' => 'debit', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'AP-8B', 'normalized_code' => 'ap-8b', 'name' => 'Accounts Payable', 'classification' => 'liability', 'normal_balance' => 'credit', 'account_subtype' => 'payable', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        $cashTitle = AccountTitle::where('company_id', $company->id)->where('code', 'CASH-8B')->firstOrFail();
        $type = CashAccountType::where('code', 'BANK_ACCOUNT')->firstOrFail();
        $cashAccount = CashAccount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'BANK-8B', 'name' => 'Phase 8B Bank', 'display_name' => 'Phase 8B Bank', 'cash_account_type_id' => $type->id, 'account_title_id' => $cashTitle->id, 'currency_id' => $currency->id, 'status' => 'active', 'version' => 1, 'activated_by' => $owner->id, 'activated_at' => now()]);
        CashAccountCapability::create(['id' => (string) Str::uuid(), 'cash_account_id' => $cashAccount->id, 'capability' => 'MAKE_PAYMENTS', 'enabled' => true, 'version' => 1]);
        $method = PaymentMethod::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-8B', 'normalized_code' => 'cash-8b', 'name' => 'Cash Payment', 'method_class' => 'CASH', 'supports_outgoing' => true, 'supports_incoming' => false, 'clearing_behavior' => 'direct', 'status' => 'active', 'version' => 1]);
        CashMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $cashAccount->id, 'direction' => 'increase', 'amount' => '1000.000000', 'currency_code' => 'PHP', 'business_date' => now()->toDateString(), 'posted_at' => now(), 'source_event_type' => 'EVT-CAS-OPENING', 'source_record_type' => 'test-opening-balance', 'source_record_id' => (string) Str::uuid(), 'source_reference' => 'TEST-OPENING-8B', 'movement_status' => 'posted', 'clearing_status' => 'not_applicable', 'reconciliation_status' => 'unreconciled']);
        $payable = $this->payable($company, $supplier, $currency, 'SINV-8B-001', '100.00');

        return [$owner, $company, $supplier, $currency, $method, $cashAccount, $payable];
    }

    private function payable(Company $company, BusinessPartner $supplier, ReferenceCurrency $currency, string $number, string $amount): PayableOpenItem
    {
        $invoice = SupplierInvoice::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'invoice_number' => $number, 'supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'external_invoice_number' => 'EXT-'.$number, 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'status' => 'posted', 'subtotal' => $amount, 'taxable_amount' => $amount, 'total' => $amount, 'remaining_amount' => $amount, 'paid_amount' => '0.000000', 'version' => 1]);
        $payable = PayableOpenItem::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'supplier_id' => $supplier->id, 'source_supplier_invoice_id' => $invoice->id, 'source_document_number' => $number, 'currency_id' => $currency->id, 'original_amount' => $amount, 'paid_amount' => '0.000000', 'remaining_amount' => $amount, 'due_date' => now()->toDateString(), 'settlement_status' => 'unpaid', 'due_status' => 'due_today', 'hold_status' => 'not_held', 'version' => 1, 'last_calculated_at' => now()]);
        $invoice->update(['payable_open_item_id' => $payable->id]);

        return $payable;
    }

    private function client(User $user, Company $company)
    {
        return $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
    }
}
