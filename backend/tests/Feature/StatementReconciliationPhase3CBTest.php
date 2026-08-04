<?php

namespace Tests\Feature;

use App\Models\CashAccountType;
use App\Models\CashMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatementReconciliationPhase3CBTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_statement_lines_validate_without_creating_a_movement_and_duplicate_evidence_is_blocked(): void
    {
        Storage::fake('local');
        [$company, $client, $account] = $this->accountContext();
        $batch = $client->postJson('/api/v1/cash-accounts/statement-imports', ['cash_account_id' => $account, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'opening_statement_balance' => '0', 'closing_statement_balance' => '10'])->assertCreated()->json('data');
        $client->post('/api/v1/cash-accounts/statement-imports/'.$batch['id'].'/evidence', ['file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf')])->assertOk();
        $client->postJson('/api/v1/cash-accounts/statement-imports/'.$batch['id'].'/lines', ['transaction_date' => '2026-01-10', 'description' => 'Deposit', 'credit_amount' => '10'])->assertCreated()->assertJsonPath('data.signed_amount', '10.000000');
        $validated = $client->postJson('/api/v1/cash-accounts/statement-imports/'.$batch['id'].'/validate')->assertOk()->assertJsonPath('data.status', 'ready')->json('data');
        $this->assertSame(0, CashMovement::count());
        $this->assertSame('10.000000', $validated['total_credit']);
        $duplicateBatch = $client->postJson('/api/v1/cash-accounts/statement-imports', ['cash_account_id' => $account, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'opening_statement_balance' => '0', 'closing_statement_balance' => '10'])->assertCreated()->json('data');
        $client->post('/api/v1/cash-accounts/statement-imports/'.$duplicateBatch['id'].'/evidence', ['file' => UploadedFile::fake()->createWithContent('statement.pdf', 'same statement')])->assertOk();
        $client->postJson('/api/v1/cash-accounts/statement-imports/'.$batch['id'].'/evidence', ['file' => UploadedFile::fake()->createWithContent('statement.pdf', 'same statement')])->assertStatus(409);
        $this->assertSame((int) $company->id, (int) $client->getJson('/api/v1/context/company')->json('data.id'));
    }

    public function test_exact_match_updates_reconciliation_state_without_changing_movement_amount_and_completion_locks_then_reopen_preserves_history(): void
    {
        [$company, $client, $account] = $this->accountContext('REC-001');
        $movement = CashMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account, 'direction' => 'increase', 'amount' => '10', 'currency_code' => 'PHP', 'business_date' => '2026-01-10', 'posted_at' => now(), 'source_event_type' => 'TEST', 'source_record_type' => self::class, 'source_record_id' => 'movement-1', 'movement_status' => 'posted', 'clearing_status' => 'not_applicable', 'reconciliation_status' => 'unreconciled']);
        $batch = $client->postJson('/api/v1/cash-accounts/statement-imports', ['cash_account_id' => $account, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'opening_statement_balance' => '0', 'closing_statement_balance' => '10'])->json('data');
        $line = $client->postJson('/api/v1/cash-accounts/statement-imports/'.$batch['id'].'/lines', ['transaction_date' => '2026-01-10', 'description' => 'Deposit', 'credit_amount' => '10'])->json('data');
        $client->postJson('/api/v1/cash-accounts/statement-imports/'.$batch['id'].'/validate')->assertOk();
        $reconciliation = $client->postJson('/api/v1/cash-accounts/reconciliations', ['statement_import_batch_id' => $batch['id']])->assertCreated()->json('data');
        $client->postJson('/api/v1/cash-accounts/reconciliations/'.$reconciliation['id'].'/matches', ['statement_line_ids' => [$line['id']], 'cash_movement_ids' => [$movement->id], 'method' => 'exact'])->assertCreated();
        $this->assertSame('10.000000', (string) CashMovement::findOrFail($movement->id)->amount);
        $this->assertSame('reconciled', CashMovement::findOrFail($movement->id)->reconciliation_status);
        $client->postJson('/api/v1/cash-accounts/reconciliations/'.$reconciliation['id'].'/prepare')->assertOk();
        $client->postJson('/api/v1/cash-accounts/reconciliations/'.$reconciliation['id'].'/submit')->assertOk();
        $reviewer = User::factory()->create(['status' => 'active']);
        $reviewer->companies()->attach($company->id, ['status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Reconciliation Reviewer', 'slug' => 'reconciliation-reviewer-'.Str::lower(Str::random(8)), 'status' => 'active']);
        $role->permissions()->sync(Permission::whereIn('key', ['cash-accounts.reconciliations.review', 'cash-accounts.reconciliations.approve', 'cash-accounts.reconciliations.complete', 'cash-accounts.reconciliations.reopen'])->pluck('id')->all());
        $reviewer->roles()->attach($role->id, ['company_id' => $company->id]);
        $reviewerClient = $this->actingAs($reviewer, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $reviewerClient->postJson('/api/v1/cash-accounts/reconciliations/'.$reconciliation['id'].'/review')->assertOk();
        $reviewerClient->postJson('/api/v1/cash-accounts/reconciliations/'.$reconciliation['id'].'/approve')->assertOk();
        $reviewerClient->postJson('/api/v1/cash-accounts/reconciliations/'.$reconciliation['id'].'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $owner = $company->users()->wherePivot('is_owner', true)->firstOrFail();
        $ownerClient = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $ownerClient->postJson('/api/v1/cash-accounts/reconciliations/'.$reconciliation['id'].'/matches', ['statement_line_ids' => [$line['id']], 'cash_movement_ids' => [$movement->id], 'method' => 'exact'])->assertStatus(409);
        $reviewerClient->postJson('/api/v1/cash-accounts/reconciliations/'.$reconciliation['id'].'/reopen', ['reason' => 'Investigate reconciliation evidence'])->assertOk()->assertJsonPath('data.status', 'reopened');
        $this->assertDatabaseHas('reconciliation_completions', ['reconciliation_id' => $reconciliation['id'], 'status' => 'completed']);
        $this->assertDatabaseHas('reconciliation_history', ['reconciliation_id' => $reconciliation['id'], 'event' => 'reopen']);
    }

    public function test_reconciliation_adjustment_reuses_cash_movement_workflow_and_posts_a_balanced_source_effect(): void
    {
        Storage::fake('local');
        [$company, $client, $account] = $this->accountContext('ADJ-001');
        $offset = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'ADJ-OFF-'.Str::random(4), 'name' => 'Adjustment expense', 'classification' => 'expense', 'normal_balance' => 'debit'])->json('data.id');
        $reason = $client->postJson('/api/v1/master-registries/reason-codes', ['code' => 'ADJ-REASON-'.Str::random(4), 'name' => 'Reconciliation adjustment', 'domain' => 'CASH_MOVEMENT'])->json('data.id');
        $batch = $client->postJson('/api/v1/cash-accounts/statement-imports', ['cash_account_id' => $account, 'period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'opening_statement_balance' => '0', 'closing_statement_balance' => '5'])->json('data');
        $line = $client->postJson('/api/v1/cash-accounts/statement-imports/'.$batch['id'].'/lines', ['transaction_date' => '2026-02-10', 'description' => 'Bank charge evidence', 'credit_amount' => '5'])->json('data');
        $client->postJson('/api/v1/cash-accounts/statement-imports/'.$batch['id'].'/validate')->assertOk();
        $reconciliation = $client->postJson('/api/v1/cash-accounts/reconciliations', ['statement_import_batch_id' => $batch['id']])->json('data');
        $outstanding = $client->postJson('/api/v1/cash-accounts/reconciliations/'.$reconciliation['id'].'/outstanding-items', ['source_type' => 'statement_line', 'source_id' => $line['id'], 'classification' => 'UNIDENTIFIED', 'reason' => 'Controlled adjustment review'])->json('data');
        $adjustment = $client->postJson('/api/v1/cash-accounts/reconciliation-adjustments', ['reconciliation_id' => $reconciliation['id'], 'outstanding_item_id' => $outstanding['id'], 'direction' => 'increase', 'amount' => '5', 'offset_account_title_id' => $offset, 'reason_code_id' => $reason, 'business_date' => '2026-02-10', 'explanation' => 'Governed reconciliation adjustment'])->assertCreated()->json('data');
        $client->post('/api/v1/cash-accounts/reconciliation-adjustments/'.$adjustment['id'].'/evidence', ['file' => UploadedFile::fake()->create('adjustment.pdf', 100, 'application/pdf')])->assertOk();
        $client->postJson('/api/v1/cash-accounts/reconciliation-adjustments/'.$adjustment['id'].'/submit')->assertOk();
        $reviewer = User::factory()->create(['status' => 'active']);
        $reviewer->companies()->attach($company->id, ['status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Adjustment Reviewer', 'slug' => 'adjustment-reviewer-'.Str::lower(Str::random(8)), 'status' => 'active']);
        $role->permissions()->sync(Permission::whereIn('key', ['cash-accounts.reconciliation-adjustments.approve', 'cash-accounts.reconciliation-adjustments.post'])->pluck('id')->all());
        $reviewer->roles()->attach($role->id, ['company_id' => $company->id]);
        $reviewerClient = $this->actingAs($reviewer, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $reviewerClient->postJson('/api/v1/cash-accounts/reconciliation-adjustments/'.$adjustment['id'].'/approve')->assertOk();
        $posted = $reviewerClient->postJson('/api/v1/cash-accounts/reconciliation-adjustments/'.$adjustment['id'].'/post')->assertOk()->json('data');
        $this->assertSame('posted', $posted['status']);
        $this->assertSame(1, CashMovement::where('source_record_type', 'App\\Models\\CashMovementDocument')->where('source_record_id', $posted['cash_movement_document_id'])->where('movement_status', 'posted')->count());
    }

    private function accountContext(string $code = 'BANK-REC'): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'owner-'.Str::random(8).'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Reconciliation Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = $user->companies()->firstOrFail();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $title = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'BANK-REC-'.Str::random(4), 'name' => 'Bank asset', 'classification' => 'asset', 'normal_balance' => 'debit'])->json('data.id');
        $type = CashAccountType::where('code', 'BANK_ACCOUNT')->firstOrFail();
        $account = $client->postJson('/api/v1/cash-accounts', ['code' => $code, 'name' => 'Reconciliation bank', 'cash_account_type_id' => $type->id, 'account_title_id' => $title, 'currency_id' => $company->default_currency_id, 'capabilities' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'STATEMENT_IMPORT', 'RECONCILE']])->json('data.id');
        $client->postJson('/api/v1/cash-accounts/'.$account.'/activate', ['reason' => 'Bank reconciliation account ready'])->assertOk();

        return [$company, $client, $account];
    }
}
