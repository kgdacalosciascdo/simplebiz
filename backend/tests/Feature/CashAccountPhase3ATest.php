<?php

namespace Tests\Feature;

use App\Models\AccountingTransactionLine;
use App\Models\Attachment;
use App\Models\CashAccount;
use App\Models\CashAccountType;
use App\Models\CashMovement;
use App\Models\OpeningBalance;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CashAccountPhase3ATest extends TestCase
{
    use RefreshDatabase;

    public function test_account_types_are_seeded_and_account_creation_does_not_create_money(): void
    {
        [$user, $company, $client] = $this->ownerContext();
        $type = CashAccountType::where('code', 'CASH_BOX')->firstOrFail();
        $title = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'CASH', 'name' => 'Cash on Hand', 'classification' => 'asset', 'normal_balance' => 'debit'])->assertCreated()->json('data.id');
        $account = $client->postJson('/api/v1/cash-accounts', ['code' => 'CASH-001', 'name' => 'Main Cash Box', 'cash_account_type_id' => $type->id, 'account_title_id' => $title, 'currency_id' => $company->default_currency_id, 'account_identifier' => '123456789'])->assertCreated();
        $account->assertJsonPath('data.status', 'draft')->assertJsonPath('data.masked_account_identifier', '••••6789')->assertJsonMissingPath('data.account_identifier');
        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, OpeningBalance::count());
        $capabilities = CashAccount::firstOrFail()->capabilities()->where('enabled', true)->pluck('capability')->all();
        sort($capabilities);
        $expectedCapabilities = ['CASH_COUNT', 'MAKE_PAYMENTS', 'RECEIVE_FUNDS', 'TRANSFER_IN', 'TRANSFER_OUT'];
        sort($expectedCapabilities);
        $this->assertSame($expectedCapabilities, $capabilities);
    }

    public function test_physical_account_activation_requires_a_custodian_and_capabilities_are_separate_from_permission(): void
    {
        [$user, $company, $client] = $this->ownerContext();
        $title = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'CASH2', 'name' => 'Cash Box Asset', 'classification' => 'asset', 'normal_balance' => 'debit'])->json('data.id');
        $type = CashAccountType::where('code', 'CASH_BOX')->firstOrFail();
        $account = $client->postJson('/api/v1/cash-accounts', ['code' => 'CASH-002', 'name' => 'Second Cash Box', 'cash_account_type_id' => $type->id, 'account_title_id' => $title, 'currency_id' => $company->default_currency_id])->json('data');
        $client->postJson("/api/v1/cash-accounts/{$account['id']}/activate", ['reason' => 'Attempt without custody'])->assertStatus(409)->assertJsonPath('errors.dependency', 'custodian');
        $client->postJson("/api/v1/cash-accounts/{$account['id']}/custodians", ['user_id' => $user->id, 'is_primary' => true, 'effective_from' => now()->toDateString()])->assertCreated();
        $client->postJson("/api/v1/cash-accounts/{$account['id']}/activate", ['reason' => 'Custodian assigned'])->assertOk()->assertJsonPath('data.status', 'active');
        $fresh = $client->getJson("/api/v1/cash-accounts/{$account['id']}")->json('data');
        $client->patchJson("/api/v1/cash-accounts/{$account['id']}/capabilities", ['version' => $fresh['version'], 'capabilities' => ['RECEIVE_FUNDS']])->assertOk();
    }

    public function test_opening_balance_requires_evidence_approval_and_configuration_then_changes_derived_balance_and_reverses(): void
    {
        Storage::fake('local');
        [$user, $company, $client] = $this->ownerContext();
        $title = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'CASH3', 'name' => 'Cash Account Asset', 'classification' => 'asset', 'normal_balance' => 'debit'])->json('data.id');
        $offset = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'OPENING', 'name' => 'Opening Balance Clearing', 'classification' => 'equity', 'normal_balance' => 'credit'])->json('data.id');
        $company->forceFill(['opening_balance_offset_account_title_id' => $offset])->save();
        $reason = $client->postJson('/api/v1/master-registries/reason-codes', ['code' => 'MIGRATION', 'name' => 'Migration opening balance', 'domain' => 'OPENING_BALANCE'])->json('data.id');
        $type = CashAccountType::where('code', 'BANK_ACCOUNT')->firstOrFail();
        $account = $client->postJson('/api/v1/cash-accounts', ['code' => 'BANK-001', 'name' => 'Operating Bank', 'cash_account_type_id' => $type->id, 'account_title_id' => $title, 'currency_id' => $company->default_currency_id])->json('data');
        $client->postJson("/api/v1/cash-accounts/{$account['id']}/activate", ['reason' => 'Bank profile complete'])->assertOk();
        $opening = $client->postJson('/api/v1/cash-accounts/opening-balances', ['cash_account_id' => $account['id'], 'currency_id' => $company->default_currency_id, 'effective_date' => now()->toDateString(), 'amount' => '1000.50', 'opening_source' => 'migration', 'migration_reference' => 'MIG-001', 'reason_code_id' => $reason, 'explanation' => 'Opening balance from approved migration'])->assertCreated()->json('data');
        $client->postJson("/api/v1/cash-accounts/opening-balances/{$opening['id']}/submit")->assertStatus(409)->assertJsonPath('errors.dependency', 'evidence');
        $client->withHeader('Idempotency-Key', 'evidence-1')->post("/api/v1/cash-accounts/opening-balances/{$opening['id']}/evidence", ['file' => UploadedFile::fake()->create('opening.pdf', 100, 'application/pdf')])->assertCreated();
        $this->assertSame(1, Attachment::count());
        $this->assertSame(1, OpeningBalance::findOrFail($opening['id'])->attachments()->count());
        $client->postJson("/api/v1/cash-accounts/opening-balances/{$opening['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $reviewer = User::factory()->create(['name' => 'Reviewer', 'email' => 'reviewer@example.test', 'status' => 'active']);
        $reviewer->companies()->attach($company->id, ['status' => 'active', 'is_owner' => false]);
        $admin = Role::where('company_id', $company->id)->where('system_key', 'administrator')->firstOrFail();
        $reviewer->roles()->attach($admin->id, ['company_id' => $company->id]);
        $reviewerClient = $this->actingAs($reviewer, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $reviewerClient->postJson("/api/v1/cash-accounts/opening-balances/{$opening['id']}/approve")->assertOk();
        $client->withHeader('Idempotency-Key', 'post-1')->postJson("/api/v1/cash-accounts/opening-balances/{$opening['id']}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $client->getJson("/api/v1/cash-accounts/{$account['id']}/balance")->assertOk()->assertJsonPath('data.posted_balance', '1000.5');
        $this->assertSame(2, AccountingTransactionLine::count());
        $client->withHeader('Idempotency-Key', 'reverse-1')->postJson("/api/v1/cash-accounts/opening-balances/{$opening['id']}/reverse", ['reason' => 'Migration corrected'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $client->getJson("/api/v1/cash-accounts/{$account['id']}/balance")->assertOk()->assertJsonPath('data.posted_balance', '0');
        $this->assertSame(2, CashMovement::where('cash_account_id', $account['id'])->where('movement_status', 'posted')->count());
    }

    private function ownerContext(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Acme Cash Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = $user->companies()->firstOrFail();

        return [$user, $company, $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id)];
    }
}
