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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpensesPhase9BTest extends TestCase
{
    use RefreshDatabase;

    public function test_reimbursement_claim_posts_and_hands_a_distinct_obligation_to_mds500(): void
    {
        [$owner, $company, $payee, $currency, $category, $liability, $method, $cashAccount] = $this->context(true);
        $client = $this->client($owner, $company);
        $expense = $client->postJson('/api/v1/expenses', $this->expenseInput($payee, $currency, $category, ['settlement_intent' => 'reimbursement']))->assertCreated();
        $id = $expense->json('data.id');
        $claim = $client->postJson('/api/v1/expenses/reimbursements', ['claimant_business_partner_id' => $payee->id, 'expense_ids' => [$id], 'business_purpose' => 'Client travel'])->assertCreated();
        $claimId = $claim->json('data.id');
        $client->postJson('/api/v1/expenses/reimbursements/'.$claimId.'/submit')->assertOk()->assertJsonPath('data.status', 'approved');
        $posted = $client->postJson('/api/v1/expenses/reimbursements/'.$claimId.'/post')->assertOk()->assertJsonPath('data.status', 'payment_ready');
        $obligationId = $posted->json('data.obligation.id');
        $payment = $client->postJson('/api/v1/expenses/reimbursements/'.$claimId.'/pay', ['payment_method_id' => $method->id, 'cash_account_id' => $cashAccount->id, 'payment_date' => now()->toDateString()])->assertCreated();

        $this->assertDatabaseHas('reimbursement_obligations', ['id' => $obligationId, 'remaining_amount' => '100.000000']);
        $this->assertDatabaseHas('payment_instruction_sources', ['payment_instruction_id' => $payment->json('data.id'), 'reimbursement_obligation_id' => $obligationId, 'expense_obligation_id' => null]);
        $this->assertDatabaseHas('payment_instructions', ['id' => $payment->json('data.id'), 'source_kind' => 'reimbursement_obligation']);
        $this->assertDatabaseHas('accounting_transaction_lines', ['account_title_id' => $liability->id, 'credit' => '100.000000']);
    }

    public function test_recurring_generation_is_idempotent_and_keeps_a_draft_boundary(): void
    {
        [$owner, $company, $payee, $currency, $category] = $this->context();
        $client = $this->client($owner, $company);
        $template = $client->postJson('/api/v1/expenses/recurring', ['name' => 'Office rent', 'currency_id' => $currency->id, 'payee_id' => $payee->id, 'expense_category_id' => $category->id, 'expense_account_title_id' => $category->account_title_id, 'frequency' => 'monthly', 'next_run_date' => '2026-09-01', 'amount' => '500', 'description' => 'Monthly office rent'])->assertCreated();
        $id = $template->json('data.id');
        $first = $client->postJson('/api/v1/expenses/recurring/'.$id.'/generate', ['scheduled_date' => '2026-09-01'])->assertCreated();
        $second = $client->postJson('/api/v1/expenses/recurring/'.$id.'/generate', ['scheduled_date' => '2026-09-01'])->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('draft', $first->json('data.expense.status'));
        $this->assertDatabaseCount('recurring_expense_occurrences', 1);
    }

    public function test_credit_preserves_the_original_expense_and_reduces_its_open_obligation(): void
    {
        [$owner, $company, $payee, $currency, $category] = $this->context();
        $client = $this->client($owner, $company);
        $expense = $client->postJson('/api/v1/expenses', $this->expenseInput($payee, $currency, $category))->assertCreated();
        $id = $expense->json('data.id');
        $client->postJson('/api/v1/expenses/'.$id.'/submit')->assertOk();
        $posted = $client->postJson('/api/v1/expenses/'.$id.'/post', ['version' => 2])->assertOk();
        $entry = $client->postJson('/api/v1/expenses/'.$id.'/correction', ['adjustment_type' => 'credit', 'amount' => '10', 'reason' => 'Supplier credit note'])->assertCreated();

        $this->assertSame('credit', $entry->json('data.adjustment_type'));
        $this->assertDatabaseHas('expenses', ['id' => $id, 'status' => 'adjusted', 'total' => '100.000000']);
        $this->assertDatabaseHas('expense_obligations', ['id' => $posted->json('data.obligation.id'), 'credited_amount' => '10.000000', 'remaining_amount' => '90.000000']);
    }

    public function test_import_preview_and_apply_only_create_draft_expenses(): void
    {
        [$owner, $company, $payee, $currency, $category] = $this->context();
        $client = $this->client($owner, $company);
        $csv = "business_date,description,currency_id,expense_category_id,payee_id,amount\n".now()->toDateString().",Imported supplies,{$currency->id},{$category->id},{$payee->id},45.00\n";
        $preview = $client->post('/api/v1/expenses/import/preview', ['file' => UploadedFile::fake()->createWithContent('expenses.csv', $csv)])->assertCreated();
        $batchId = $preview->json('data.id');
        $client->postJson('/api/v1/expenses/import/'.$batchId.'/apply')->assertOk();

        $this->assertDatabaseHas('expense_import_batches', ['id' => $batchId, 'status' => 'applied', 'created_count' => 1]);
        $this->assertDatabaseHas('expenses', ['description' => 'Imported supplies', 'status' => 'draft', 'total' => '45.000000']);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    private function context(bool $withPayment = false): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Phase 9B Owner', 'email' => 'expenses-9b-'.Str::lower(Str::random(8)).'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Expenses 9B Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));
        $company = $owner->companies()->firstOrFail();
        $currency = ReferenceCurrency::where('company_id', $company->id)->where('code', 'PHP')->firstOrFail();
        $payee = BusinessPartner::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'PAYEE-9B', 'normalized_code' => 'payee-9b', 'party_type' => 'organization', 'official_name' => 'Phase 9B Payee', 'display_name' => 'Phase 9B Payee', 'status' => 'active', 'version' => 1]);
        $expenseAccount = AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'EXP-9B', 'normalized_code' => 'exp-9b', 'name' => 'Office Expense', 'classification' => 'expense', 'normal_balance' => 'debit', 'account_subtype' => 'operating_expense', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        $liability = AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'AP-9B', 'normalized_code' => 'ap-9b', 'name' => 'Accounts Payable', 'classification' => 'liability', 'normal_balance' => 'credit', 'account_subtype' => 'payable', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
        $category = ExpenseCategory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'OFFICE-9B', 'normalized_code' => 'office-9b', 'name' => 'Office Expense', 'account_title_id' => $expenseAccount->id, 'status' => 'active', 'version' => 1]);
        $method = $cashAccount = null;
        if ($withPayment) {
            $cashTitle = AccountTitle::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-9B', 'normalized_code' => 'cash-9b', 'name' => 'Cash Account 9B', 'classification' => 'asset', 'normal_balance' => 'debit', 'posting_eligible' => true, 'status' => 'active', 'version' => 1]);
            $type = CashAccountType::where('code', 'BANK_ACCOUNT')->firstOrFail();
            $cashAccount = CashAccount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'BANK-9B', 'name' => 'Phase 9B Bank', 'display_name' => 'Phase 9B Bank', 'cash_account_type_id' => $type->id, 'account_title_id' => $cashTitle->id, 'currency_id' => $currency->id, 'status' => 'active', 'version' => 1, 'activated_by' => $owner->id, 'activated_at' => now()]);
            CashAccountCapability::create(['id' => (string) Str::uuid(), 'cash_account_id' => $cashAccount->id, 'capability' => 'MAKE_PAYMENTS', 'enabled' => true, 'version' => 1]);
            $method = PaymentMethod::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => 'CASH-9B', 'normalized_code' => 'cash-9b', 'name' => 'Cash Payment', 'method_class' => 'CASH', 'supports_outgoing' => true, 'supports_incoming' => false, 'clearing_behavior' => 'direct', 'status' => 'active', 'version' => 1]);
        }

        return [$owner, $company, $payee, $currency, $category, $liability, $method, $cashAccount];
    }

    private function expenseInput(BusinessPartner $payee, ReferenceCurrency $currency, ExpenseCategory $category, array $overrides = []): array
    {
        return ['business_date' => now()->toDateString(), 'payee_id' => $payee->id, 'description' => 'Phase 9B office expense', 'currency_id' => $currency->id, 'settlement_intent' => $overrides['settlement_intent'] ?? 'pay_later', 'lines' => [['expense_category_id' => $category->id, 'description' => 'Office supplies', 'quantity' => '1', 'unit_amount' => '100.00']]];
    }

    private function client(User $user, Company $company)
    {
        return $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
    }
}
