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
use App\Models\PaymentMethod;
use App\Models\ReferenceCurrency;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentsPhase8ATest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_payment_confirms_cash_only_at_confirmation_and_allocates_to_payable(): void
    {
        [$owner, $company, $supplier, $currency, $method, $cashAccount, $payable] = $this->context();
        $client = $this->client($owner, $company);
        $paymentDate = now()->toDateString();

        $created = $client->postJson('/api/v1/payments', [
            'supplier_id' => $supplier->id,
            'currency_id' => $currency->id,
            'payment_method_id' => $method->id,
            'cash_account_id' => $cashAccount->id,
            'payment_date' => $paymentDate,
            'reference' => 'PAY-8A-001',
            'sources' => [['payable_open_item_id' => $payable->id, 'amount' => '75.00']],
        ])->assertCreated()->assertJsonPath('data.status', 'draft');
        $paymentId = $created->json('data.id');

        $this->assertDatabaseCount('cash_movements', 1);
        $client->postJson('/api/v1/payments/'.$paymentId.'/submit')->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $client->postJson('/api/v1/payments/'.$paymentId.'/approve')->assertOk()->assertJsonPath('data.status', 'ready');
        $client->postJson('/api/v1/payments/'.$paymentId.'/release')->assertOk()->assertJsonPath('data.status', 'released');
        $this->assertDatabaseCount('cash_movements', 1);

        $confirmed = $client->postJson('/api/v1/payments/'.$paymentId.'/confirm-cash', [
            'confirmed_amount' => '75.00',
            'confirmed_date' => $paymentDate,
            'recipient_acknowledgement' => 'Supplier representative acknowledged receipt.',
            'evidence_reference' => 'signed-receipt-8a-001',
        ])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $confirmationId = $confirmed->json('data.confirmations.0.id');

        $this->assertDatabaseHas('cash_movements', ['source_record_type' => 'App\\Models\\PaymentInstruction', 'source_record_id' => $paymentId, 'direction' => 'decrease', 'amount' => '75.000000']);
        $this->assertDatabaseHas('payment_confirmations', ['payment_instruction_id' => $paymentId, 'status' => 'confirmed', 'manual_confirmation' => 1]);
        $this->assertSame('100.000000', (string) $payable->fresh()->remaining_amount);

        $partial = $client->postJson('/api/v1/payments/'.$paymentId.'/allocate', ['payment_confirmation_id' => $confirmationId, 'payable_open_item_id' => $payable->id, 'amount' => '50.00', 'allocation_date' => $paymentDate])->assertOk()->assertJsonPath('data.status', 'partially_allocated');
        $client->postJson('/api/v1/payments/'.$paymentId.'/allocate', ['payment_confirmation_id' => $confirmationId, 'payable_open_item_id' => $payable->id, 'amount' => '25.00', 'allocation_date' => $paymentDate, 'version' => $partial->json('data.version')])->assertOk()->assertJsonPath('data.status', 'allocated');

        $this->assertDatabaseHas('payment_allocations', ['payment_instruction_id' => $paymentId, 'amount' => '50.000000', 'status' => 'applied']);
        $this->assertSame('25.000000', (string) $payable->fresh()->remaining_amount);
        $this->assertSame('75.000000', (string) $payable->fresh()->paid_amount);
        $this->assertSame('posted', $payable->sourceInvoice()->firstOrFail()->status);
    }

    public function test_pending_and_failed_channel_outcomes_do_not_create_cash_effects(): void
    {
        [$owner, $company, $supplier, $currency, $method, $cashAccount, $payable] = $this->context();
        $client = $this->client($owner, $company);
        $data = ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'payment_method_id' => $method->id, 'cash_account_id' => $cashAccount->id, 'payment_date' => now()->toDateString(), 'reference' => 'PAY-8A-002', 'sources' => [['payable_open_item_id' => $payable->id, 'amount' => '20.00']]];
        $paymentId = $client->postJson('/api/v1/payments', $data)->assertCreated()->json('data.id');
        $client->postJson('/api/v1/payments/'.$paymentId.'/submit')->assertOk();
        $client->postJson('/api/v1/payments/'.$paymentId.'/approve')->assertOk();
        $client->postJson('/api/v1/payments/'.$paymentId.'/release')->assertOk();
        $client->postJson('/api/v1/payments/'.$paymentId.'/pending', ['response_code' => 'PENDING', 'response_message' => 'Awaiting bank confirmation'])->assertOk()->assertJsonPath('data.status', 'pending_confirmation');
        $this->assertDatabaseCount('cash_movements', 1);
        $client->postJson('/api/v1/payments/'.$paymentId.'/fail', ['reason' => 'Bank rejected the instruction'])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame('100.000000', (string) $payable->fresh()->remaining_amount);
    }

    public function test_approved_payment_request_converts_once_and_preserves_request_history(): void
    {
        [$owner, $company, $supplier, $currency, $method, $cashAccount, $payable] = $this->context();
        $client = $this->client($owner, $company);
        $request = $client->postJson('/api/v1/payments/requests', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'requested_payment_date' => now()->toDateString(), 'reason' => 'Approved supplier payment request', 'sources' => [['payable_open_item_id' => $payable->id, 'amount' => '30.00']]])->assertCreated();
        $requestId = $request->json('data.id');
        $client->postJson('/api/v1/payments/requests/'.$requestId.'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $client->postJson('/api/v1/payments/requests/'.$requestId.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $payment = $client->postJson('/api/v1/payments/requests/'.$requestId.'/convert', ['payment_method_id' => $method->id, 'cash_account_id' => $cashAccount->id, 'payment_date' => now()->toDateString()])->assertCreated()->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('payment_requests', ['id' => $requestId, 'status' => 'converted']);
        $this->assertSame(4, (int) $this->getConnection()->table('payment_request_status_histories')->where('payment_request_id', $requestId)->count());
        $this->assertSame($requestId, $payment->json('data.payment_request_id'));
    }

    public function test_payment_workbench_excludes_held_payables_and_request_history_is_scoped(): void
    {
        [$owner, $company, $supplier, $currency, $method, $cashAccount, $payable] = $this->context();
        $payable->update(['hold_status' => 'held', 'hold_reason' => 'Documentation review']);
        $client = $this->client($owner, $company);

        $client->getJson('/api/v1/payments/workbench')->assertOk()->assertJsonPath('meta.total', 0);
        $request = $client->postJson('/api/v1/payments/requests', ['supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'requested_payment_date' => now()->toDateString(), 'reason' => 'Held item request test', 'sources' => [['payable_open_item_id' => $payable->id, 'amount' => '10.00']]]);
        $request->assertStatus(409);
        $this->assertDatabaseCount('payment_request_status_histories', 0);
    }

    private function context(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Phase 8A Owner', 'email' => 'payments-8a-'.Str::lower(Str::random(8)).'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Payments 8A Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));
        $company = $owner->companies()->firstOrFail();
        $currency = ReferenceCurrency::where('company_id', $company->id)->where('code', 'PHP')->firstOrFail();
        $supplier = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'SUP-8A', 'normalized_code' => 'sup-8a', 'party_type' => 'organization', 'official_name' => 'Phase 8A Supplier', 'display_name' => 'Phase 8A Supplier', 'status' => 'active', 'version' => 1]);
        BusinessPartnerRole::create(['id' => (string) Str::uuid(), 'business_partner_id' => $supplier->id, 'company_id' => $company->id, 'role' => 'supplier', 'status' => 'active', 'version' => 1]);
        $cashTitle = AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-8A', 'normalized_code' => 'cash-8a', 'name' => 'Cash Account 8A', 'classification' => 'asset', 'normal_balance' => 'debit', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        $payableTitle = AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'AP-8A', 'normalized_code' => 'ap-8a', 'name' => 'Accounts Payable', 'classification' => 'liability', 'normal_balance' => 'credit', 'account_subtype' => 'payable', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        $type = CashAccountType::where('code', 'BANK_ACCOUNT')->firstOrFail();
        $cashAccount = CashAccount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'BANK-8A', 'name' => 'Phase 8A Bank', 'display_name' => 'Phase 8A Bank', 'cash_account_type_id' => $type->id, 'account_title_id' => $cashTitle->id, 'currency_id' => $currency->id, 'status' => 'active', 'version' => 1, 'activated_by' => $owner->id, 'activated_at' => now()]);
        CashAccountCapability::create(['id' => (string) Str::uuid(), 'cash_account_id' => $cashAccount->id, 'capability' => 'MAKE_PAYMENTS', 'enabled' => true, 'version' => 1]);
        PaymentMethod::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-8A', 'normalized_code' => 'cash-8a', 'name' => 'Cash Payment', 'method_class' => 'CASH', 'supports_outgoing' => true, 'supports_incoming' => false, 'clearing_behavior' => 'direct', 'status' => 'active', 'version' => 1]);
        $method = PaymentMethod::where('company_id', $company->id)->where('code', 'CASH-8A')->firstOrFail();
        CashMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $cashAccount->id, 'direction' => 'increase', 'amount' => '1000.000000', 'currency_code' => 'PHP', 'business_date' => now()->toDateString(), 'posted_at' => now(), 'source_event_type' => 'EVT-CAS-OPENING', 'source_record_type' => 'test-opening-balance', 'source_record_id' => (string) Str::uuid(), 'source_reference' => 'TEST-OPENING-8A', 'movement_status' => 'posted', 'clearing_status' => 'not_applicable', 'reconciliation_status' => 'unreconciled']);
        $invoice = SupplierInvoice::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'invoice_number' => 'SINV-8A-001', 'supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'external_invoice_number' => 'EXT-8A-001', 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'status' => 'posted', 'subtotal' => '100.000000', 'taxable_amount' => '100.000000', 'total' => '100.000000', 'remaining_amount' => '100.000000', 'paid_amount' => '0.000000', 'version' => 1]);
        $payable = PayableOpenItem::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'supplier_id' => $supplier->id, 'source_supplier_invoice_id' => $invoice->id, 'source_document_number' => $invoice->invoice_number, 'currency_id' => $currency->id, 'original_amount' => '100.000000', 'paid_amount' => '0.000000', 'remaining_amount' => '100.000000', 'due_date' => now()->toDateString(), 'settlement_status' => 'unpaid', 'due_status' => 'due_today', 'hold_status' => 'not_held', 'version' => 1, 'last_calculated_at' => now()]);
        $invoice->update(['payable_open_item_id' => $payable->id]);

        return [$owner, $company, $supplier, $currency, $method, $cashAccount, $payable, $payableTitle];
    }

    private function client(User $user, Company $company)
    {
        return $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
    }
}
