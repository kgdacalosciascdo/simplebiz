<?php

namespace Tests\Feature;

use App\Models\AccountingTransactionLine;
use App\Models\CashAccountType;
use App\Models\CashMovement;
use App\Models\CashMovementDocument;
use App\Models\CashTransferDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CashMovementPhase3BTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_cash_in_posts_one_movement_with_balanced_accounting_and_reverses(): void
    {
        Storage::fake('local');
        [$user, $company, $client] = $this->ownerContext();
        [$account, $title, $offset, $reason] = $this->accountContext($client, $company, 'IN-001', 'CASH-IN');
        $draft = $client->withHeader('Idempotency-Key', 'cash-in-create')->postJson('/api/v1/cash-accounts/cash-in', ['movement_purpose' => 'DIRECT_CASH_IN', 'cash_account_id' => $account, 'currency_id' => $company->default_currency_id, 'amount' => '250.75', 'business_date' => now()->toDateString(), 'source_type' => 'INTEREST_INCOME', 'offset_account_title_id' => $offset, 'reason_code_id' => $reason, 'explanation' => 'Interest received directly into the bank account'])->assertCreated()->json('data');
        $this->assertSame(0, CashMovement::where('source_record_type', CashMovementDocument::class)->count());
        $client->withHeader('Idempotency-Key', 'cash-in-evidence')->post('/api/v1/cash-accounts/cash-in/'.$draft['id'].'/evidence', ['file' => UploadedFile::fake()->create('interest.pdf', 100, 'application/pdf')])->assertCreated();
        $client->postJson('/api/v1/cash-accounts/cash-in/'.$draft['id'].'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $reviewerClient = $this->reviewerContext($company, ['cash-accounts.cash-in.approve', 'cash-accounts.cash-in.review']);
        $reviewerClient->postJson('/api/v1/cash-accounts/cash-in/'.$draft['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $posted = $client->withHeader('Idempotency-Key', 'cash-in-post')->postJson('/api/v1/cash-accounts/cash-in/'.$draft['id'].'/post')->assertOk()->assertJsonPath('data.status', 'posted')->json('data');
        $this->assertSame(1, CashMovement::where('source_record_type', CashMovementDocument::class)->where('source_record_id', $draft['id'])->count());
        $client->getJson('/api/v1/cash-accounts/'.$account.'/balance')->assertOk()->assertJsonPath('data.posted_balance', '250.75');
        $this->assertSame(2, AccountingTransactionLine::where('accounting_transaction_id', $posted['accounting_transaction_id'])->count());
        $client->withHeader('Idempotency-Key', 'cash-in-reverse')->postJson('/api/v1/cash-accounts/cash-in/'.$draft['id'].'/reverse', ['reason' => 'Correction of direct inflow'])->assertOk();
        $client->getJson('/api/v1/cash-accounts/'.$account.'/balance')->assertOk()->assertJsonPath('data.posted_balance', '0');
        $this->assertSame(2, CashMovement::where('cash_account_id', $account)->where('movement_status', 'posted')->count());
    }

    public function test_cash_out_blocks_negative_balance_and_transfer_posts_two_atomic_legs(): void
    {
        Storage::fake('local');
        [$user, $company, $client] = $this->ownerContext();
        [$source, $sourceTitle, $offset, $reason] = $this->accountContext($client, $company, 'OUT-001', 'CASH-OUT');
        [$destination] = $this->accountContext($client, $company, 'OUT-002', 'CASH-DEST');
        $cashOut = $client->postJson('/api/v1/cash-accounts/cash-out', ['movement_purpose' => 'DIRECT_CASH_OUT', 'cash_account_id' => $source, 'currency_id' => $company->default_currency_id, 'amount' => '10', 'business_date' => now()->toDateString(), 'source_type' => 'BANK_CHARGE', 'offset_account_title_id' => $offset, 'reason_code_id' => $reason, 'explanation' => 'Bank charge'])->assertCreated()->json('data');
        $client->post('/api/v1/cash-accounts/cash-out/'.$cashOut['id'].'/evidence', ['file' => UploadedFile::fake()->create('charge.pdf', 100, 'application/pdf')])->assertCreated();
        $client->postJson('/api/v1/cash-accounts/cash-out/'.$cashOut['id'].'/submit')->assertOk();
        $reviewerClient = $this->reviewerContext($company, ['cash-accounts.cash-out.approve', 'cash-accounts.transfers.approve']);
        $reviewerClient->postJson('/api/v1/cash-accounts/cash-out/'.$cashOut['id'].'/approve')->assertOk();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $client->postJson('/api/v1/cash-accounts/cash-out/'.$cashOut['id'].'/post')->assertStatus(409)->assertJsonPath('errors.negative_balance', true);

        $company->forceFill(['allow_negative_cash_balance' => true])->save();
        $client->patchJson('/api/v1/cash-accounts/'.$source.'/capabilities', ['version' => $client->getJson('/api/v1/cash-accounts/'.$source)->json('data.version'), 'capabilities' => ['RECEIVE_FUNDS', 'MAKE_PAYMENTS', 'TRANSFER_IN', 'TRANSFER_OUT', 'ALLOW_NEGATIVE_BALANCE']])->assertOk();
        $client->postJson('/api/v1/cash-accounts/cash-out/'.$cashOut['id'].'/post', ['negative_balance_override' => true, 'negative_balance_reason' => 'Approved bank charge despite temporary negative balance'])->assertOk();

        $transfer = $client->postJson('/api/v1/cash-accounts/transfers', ['purpose' => 'INTERNAL_TRANSFER', 'source_cash_account_id' => $source, 'destination_cash_account_id' => $destination, 'currency_id' => $company->default_currency_id, 'amount' => '5', 'business_date' => now()->toDateString(), 'reason_code_id' => $this->transferReason($client), 'explanation' => 'Move funds between company accounts'])->assertCreated()->json('data');
        $client->post('/api/v1/cash-accounts/transfers/'.$transfer['id'].'/evidence', ['file' => UploadedFile::fake()->create('transfer.pdf', 100, 'application/pdf')])->assertCreated();
        $client->postJson('/api/v1/cash-accounts/transfers/'.$transfer['id'].'/submit')->assertOk();
        $reviewerClient = $this->reviewerContext($company, ['cash-accounts.transfers.approve']);
        $reviewerClient->postJson('/api/v1/cash-accounts/transfers/'.$transfer['id'].'/approve')->assertOk();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $client->postJson('/api/v1/cash-accounts/transfers/'.$transfer['id'].'/post', ['negative_balance_override' => true, 'negative_balance_reason' => 'Approved transfer reserve'])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame(2, CashMovement::where('source_record_type', CashTransferDocument::class)->where('source_record_id', $transfer['id'])->count());
        $this->assertSame(1, CashTransferDocument::whereKey($transfer['id'])->where('status', 'posted')->count());
    }

    private function accountContext($client, $company, string $code, string $titleCode): array
    {
        $title = $client->postJson('/api/v1/master-registries/account-titles', ['code' => $titleCode, 'name' => $titleCode.' Asset', 'classification' => 'asset', 'normal_balance' => 'debit'])->json('data.id');
        $offset = $client->postJson('/api/v1/master-registries/account-titles', ['code' => $titleCode.'-OFF', 'name' => $titleCode.' Offset', 'classification' => 'expense', 'normal_balance' => 'debit'])->json('data.id');
        $reason = $client->postJson('/api/v1/master-registries/reason-codes', ['code' => $titleCode.'-REASON', 'name' => $titleCode.' reason', 'domain' => 'CASH_MOVEMENT'])->json('data.id');
        $type = CashAccountType::where('code', 'BANK_ACCOUNT')->firstOrFail();
        $account = $client->postJson('/api/v1/cash-accounts', ['code' => $code, 'name' => $code.' Account', 'cash_account_type_id' => $type->id, 'account_title_id' => $title, 'currency_id' => $company->default_currency_id])->json('data.id');
        $client->postJson('/api/v1/cash-accounts/'.$account.'/activate', ['reason' => 'Account profile complete'])->assertOk();

        return [$account, $title, $offset, $reason];
    }

    private function transferReason($client): string
    {
        return $client->postJson('/api/v1/master-registries/reason-codes', ['code' => 'TRANSFER-REASON', 'name' => 'Transfer reason', 'domain' => 'TRANSFER'])->json('data.id');
    }

    private function reviewerContext($company, array $permissions)
    {
        $reviewer = User::factory()->create(['status' => 'active']);
        $reviewer->companies()->attach($company->id, ['status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Cash Reviewer', 'slug' => 'cash-reviewer-'.uniqid(), 'status' => 'active']);
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id')->all());
        $reviewer->roles()->attach($role->id, ['company_id' => $company->id]);

        return $this->actingAs($reviewer, 'sanctum')->withHeader('X-Company-ID', $company->id);
    }

    private function ownerContext(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'owner-'.uniqid().'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Acme Movement Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = $user->companies()->firstOrFail();

        return [$user, $company, $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id)];
    }
}
