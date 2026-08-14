<?php

namespace Tests\Feature;

use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\CashAccount;
use App\Models\CashAccountCapability;
use App\Models\CashAccountType;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\ReferenceCurrency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpensesPhase9ATest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_draft_uses_server_totals_and_is_listed_in_history(): void
    {
        [$owner, $company, $payee, $currency, $category] = $this->context();
        $client = $this->client($owner, $company);

        $response = $client->withHeader('Idempotency-Key', 'expense-9a-draft-001')->postJson('/api/v1/expenses', $this->expenseInput($payee, $currency, $category, ['unit_amount' => '125.00']));

        $response->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total', '125.000000');
        $id = $response->json('data.id');
        $client->getJson('/api/v1/expenses/history')->assertOk()->assertJsonPath('data.0.id', $id);
        $client->getJson('/api/v1/expenses/summary')->assertOk()->assertJsonPath('data.expenses_this_period', 0);
        $this->assertDatabaseHas('expense_lines', ['expense_id' => $id, 'line_total' => '125.000000']);
        $this->assertDatabaseHas('expense_status_histories', ['expense_id' => $id, 'event_code' => 'EVT-EXP-001']);
    }

    public function test_pay_later_expense_posts_accounting_and_creates_one_payment_ready_obligation(): void
    {
        [$owner, $company, $payee, $currency, $category, $liability] = $this->context();
        $client = $this->client($owner, $company);
        $expense = $client->postJson('/api/v1/expenses', $this->expenseInput($payee, $currency, $category, ['unit_amount' => '250.00']))->assertCreated();
        $id = $expense->json('data.id');

        $client->postJson('/api/v1/expenses/'.$id.'/submit', ['version' => $expense->json('data.version')])->assertOk()->assertJsonPath('data.status', 'approved');
        $posted = $client->postJson('/api/v1/expenses/'.$id.'/post', ['version' => 2])->assertOk()->assertJsonPath('data.status', 'payment_ready');
        $obligationId = $posted->json('data.obligation.id');

        $this->assertDatabaseHas('expenses', ['id' => $id, 'status' => 'payment_ready', 'total' => '250.000000', 'remaining_amount' => '250.000000']);
        $this->assertDatabaseHas('expense_obligations', ['id' => $obligationId, 'expense_id' => $id, 'payment_ready' => 1, 'remaining_amount' => '250.000000']);
        $this->assertDatabaseHas('business_transactions', ['transaction_type' => 'expense', 'status' => 'posted']);
        $this->assertDatabaseHas('accounting_transaction_lines', ['account_title_id' => $liability->id, 'credit' => '250.000000']);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_payment_request_hands_expense_obligation_to_mds500_without_creating_cash(): void
    {
        [$owner, $company, $payee, $currency, $category, $liability, $method, $cashAccount] = $this->context(true);
        $client = $this->client($owner, $company);
        $expense = $client->postJson('/api/v1/expenses', $this->expenseInput($payee, $currency, $category, ['unit_amount' => '80.00']))->assertCreated();
        $id = $expense->json('data.id');
        $client->postJson('/api/v1/expenses/'.$id.'/submit', ['version' => $expense->json('data.version')])->assertOk();
        $posted = $client->postJson('/api/v1/expenses/'.$id.'/post', ['version' => 2])->assertOk();

        $payment = $client->postJson('/api/v1/expenses/'.$id.'/pay', ['payment_method_id' => $method->id, 'cash_account_id' => $cashAccount->id, 'payment_date' => now()->toDateString(), 'amount' => '50.00'])->assertCreated();
        $paymentId = $payment->json('data.id');

        $payment->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('payment_instruction_sources', ['payment_instruction_id' => $paymentId, 'expense_obligation_id' => $posted->json('data.obligation.id'), 'payable_open_item_id' => null]);
        $this->assertDatabaseHas('payment_instructions', ['id' => $paymentId, 'source_kind' => 'expense_obligation', 'gross_amount' => '50.000000']);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    private function context(bool $withPayment = false): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Phase 9A Owner', 'email' => 'expenses-9a-'.Str::lower(Str::random(8)).'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Expenses 9A Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));
        $company = $owner->companies()->firstOrFail();
        $currency = ReferenceCurrency::where('company_id', $company->id)->where('code', 'PHP')->firstOrFail();
        $payee = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'PAYEE-9A', 'normalized_code' => 'payee-9a', 'party_type' => 'organization', 'official_name' => 'Phase 9A Payee', 'display_name' => 'Phase 9A Payee', 'status' => 'active', 'version' => 1]);
        $expenseAccount = AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'EXP-9A', 'normalized_code' => 'exp-9a', 'name' => 'Office Expense', 'classification' => 'expense', 'normal_balance' => 'debit', 'account_subtype' => 'operating_expense', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        $liability = AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'AP-9A', 'normalized_code' => 'ap-9a', 'name' => 'Accounts Payable', 'classification' => 'liability', 'normal_balance' => 'credit', 'account_subtype' => 'payable', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        $category = ExpenseCategory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'OFFICE-9A', 'normalized_code' => 'office-9a', 'name' => 'Office Expense', 'account_title_id' => $expenseAccount->id, 'status' => 'active', 'version' => 1]);
        $method = $cashAccount = null;
        if ($withPayment) {
            $cashTitle = AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-9A', 'normalized_code' => 'cash-9a', 'name' => 'Cash Account 9A', 'classification' => 'asset', 'normal_balance' => 'debit', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
            $type = CashAccountType::where('code', 'BANK_ACCOUNT')->firstOrFail();
            $cashAccount = CashAccount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'BANK-9A', 'name' => 'Phase 9A Bank', 'display_name' => 'Phase 9A Bank', 'cash_account_type_id' => $type->id, 'account_title_id' => $cashTitle->id, 'currency_id' => $currency->id, 'status' => 'active', 'version' => 1, 'activated_by' => $owner->id, 'activated_at' => now()]);
            CashAccountCapability::create(['id' => (string) Str::uuid(), 'cash_account_id' => $cashAccount->id, 'capability' => 'MAKE_PAYMENTS', 'enabled' => true, 'version' => 1]);
            $method = PaymentMethod::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-9A', 'normalized_code' => 'cash-9a', 'name' => 'Cash Payment', 'method_class' => 'CASH', 'supports_outgoing' => true, 'supports_incoming' => false, 'clearing_behavior' => 'direct', 'status' => 'active', 'version' => 1]);
        }

        return [$owner, $company, $payee, $currency, $category, $liability, $method, $cashAccount];
    }

    private function expenseInput(BusinessPartner $payee, ReferenceCurrency $currency, ExpenseCategory $category, array $line = []): array
    {
        return ['business_date' => now()->toDateString(), 'payee_id' => $payee->id, 'description' => 'Phase 9A office expense', 'currency_id' => $currency->id, 'settlement_intent' => 'pay_later', 'lines' => [['expense_category_id' => $category->id, 'description' => 'Office supplies', 'quantity' => '1', 'unit_amount' => $line['unit_amount'] ?? '100.00']]];
    }

    private function client(User $user, Company $company)
    {
        return $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
    }
}
