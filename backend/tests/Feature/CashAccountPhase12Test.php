<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashAccountType;
use App\Models\CashMovement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashAccountPhase12Test extends TestCase
{
    use RefreshDatabase;

    public function test_closure_requires_zero_balance_and_preserves_a_governed_lifecycle_history(): void
    {
        [$owner, $company, $client, $account] = $this->activeAccount();

        CashMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id, 'direction' => 'increase', 'amount' => '10.00', 'currency_code' => $company->currency, 'business_date' => now()->toDateString(), 'posted_at' => now(), 'source_event_type' => 'TEST', 'source_record_type' => 'Test', 'source_record_id' => 'test-1', 'movement_status' => 'posted', 'clearing_status' => 'not_applicable', 'reconciliation_status' => 'unreconciled']);
        $client->postJson("/api/v1/cash-accounts/{$account->id}/closure", ['reason' => 'Retire account', 'version' => $account->version])->assertStatus(409)->assertJsonPath('errors.blockers.0.code', 'nonzero_balance');

        $reviewer = User::factory()->create(['name' => 'Cash Reviewer', 'email' => 'cash-reviewer@example.test', 'status' => 'active']);
        $reviewer->companies()->attach($company->id, ['status' => 'active', 'is_owner' => false]);
        $admin = Role::where('company_id', $company->id)->where('system_key', 'administrator')->firstOrFail();
        $reviewer->roles()->attach($admin->id, ['company_id' => $company->id]);
        CashMovement::where('cash_account_id', $account->id)->delete();

        $account->refresh();
        $client->postJson("/api/v1/cash-accounts/{$account->id}/closure", ['reason' => 'Retire account', 'version' => $account->version])->assertOk()->assertJsonPath('data.status', 'pending_closure')->assertJsonPath('data.closure.status', 'requested');
        $pending = CashAccount::findOrFail($account->id);
        $reviewerClient = $this->actingAs($reviewer, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $reviewerClient->postJson("/api/v1/cash-accounts/{$account->id}/closure/review", ['version' => $pending->version])->assertOk()->assertJsonPath('data.closure.status', 'under_review');
        $pending->refresh();
        Storage::fake('local');
        $reviewerClient->withHeader('Idempotency-Key', 'closure-evidence')->post("/api/v1/cash-accounts/{$account->id}/closure/evidence", ['file' => UploadedFile::fake()->create('closure.pdf', 100, 'application/pdf')])->assertCreated();
        $pending->refresh();
        $reviewerClient->postJson("/api/v1/cash-accounts/{$account->id}/closure/resolve", ['version' => $pending->version])->assertOk()->assertJsonPath('data.closure.status', 'balance_resolution');
        $pending->refresh();
        $reviewerClient->postJson("/api/v1/cash-accounts/{$account->id}/closure/approve", ['version' => $pending->version])->assertOk()->assertJsonPath('data.closure.status', 'approved');
        $pending->refresh();
        $ownerClient = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $ownerClient->postJson("/api/v1/cash-accounts/{$account->id}/closure/close", ['version' => $pending->version])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closure.status', 'closed');
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $account->id, 'action' => 'EVT-CAS-024']);
    }

    public function test_financially_used_account_cannot_change_currency_or_account_title_mapping(): void
    {
        [$owner, $company, $client, $account] = $this->activeAccount('BANK-002');
        $secondTitle = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'CASH-ALT', 'name' => 'Alternative Cash Asset', 'classification' => 'asset', 'normal_balance' => 'debit'])->assertCreated()->json('data.id');
        CashMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id, 'direction' => 'increase', 'amount' => '1.00', 'currency_code' => $company->currency, 'business_date' => now()->toDateString(), 'posted_at' => now(), 'source_event_type' => 'TEST', 'source_record_type' => 'Test', 'source_record_id' => 'test-2', 'movement_status' => 'posted', 'clearing_status' => 'not_applicable', 'reconciliation_status' => 'unreconciled']);

        $client->patchJson("/api/v1/cash-accounts/{$account->id}", ['version' => $account->version, 'code' => $account->code, 'name' => $account->name, 'account_title_id' => $secondTitle])->assertStatus(409)->assertJsonPath('errors.dependency', 'financial_usage');
    }

    private function activeAccount(string $code = 'BANK-001'): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => $code.'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Cash Closure Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));
        $company = $owner->companies()->firstOrFail();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $title = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'CASH-'.substr($code, -3), 'name' => 'Cash Asset', 'classification' => 'asset', 'normal_balance' => 'debit'])->assertCreated()->json('data.id');
        $type = CashAccountType::where('code', 'BANK_ACCOUNT')->firstOrFail();
        $account = $client->postJson('/api/v1/cash-accounts', ['code' => $code, 'name' => 'Operating Bank', 'cash_account_type_id' => $type->id, 'account_title_id' => $title, 'currency_id' => $company->default_currency_id])->assertCreated()->json('data');
        $client->postJson("/api/v1/cash-accounts/{$account['id']}/activate", ['reason' => 'Profile complete'])->assertOk();

        return [$owner, $company->fresh(), $client, CashAccount::findOrFail($account['id'])];
    }
}
