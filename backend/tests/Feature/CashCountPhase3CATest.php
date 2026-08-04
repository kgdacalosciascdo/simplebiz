<?php

namespace Tests\Feature;

use App\Models\CashAccountType;
use App\Models\CashCount;
use App\Models\CashCountAttempt;
use App\Models\CashMovement;
use App\Models\CashMovementDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CashCountPhase3CATest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_count_snapshots_expected_amount_counts_denominations_and_completes_handover_without_movement(): void
    {
        Storage::fake('local');
        [$owner, $company, $client, $account, $currency] = $this->physicalAccount();
        $reason = $this->createReason($client, 'CASH_COUNT', 'COUNT-ROUTINE');
        $denomination = $client->postJson('/api/v1/cash-accounts/cash-counts/denominations', ['currency_id' => $currency, 'denomination_type' => 'NOTE', 'face_value' => '10', 'display_label' => 'Ten'])->assertCreated()->json('data.id');
        $before = CashMovement::count();
        $count = $client->postJson('/api/v1/cash-accounts/cash-counts', ['cash_account_id' => $account, 'count_type' => 'ROUTINE', 'currency_id' => $currency, 'count_date' => now()->toDateString(), 'cut_off_at' => now()->subMinute()->toIso8601String(), 'counter_id' => $owner->id, 'reason_code_id' => $reason, 'explanation' => 'Routine physical count'])->assertCreated()->json('data');
        $client->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/start')->assertOk()->assertJsonPath('data.expected_amount', '0.000000');
        $count = $client->getJson('/api/v1/cash-accounts/cash-counts/'.$count['id'])->json('data');
        $attempt = $count['current_attempt']['id'];
        $client->post('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/evidence', ['file' => UploadedFile::fake()->create('count-sheet.pdf', 100, 'application/pdf')])->assertCreated();
        $client->patchJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/attempts/'.$attempt, ['version' => 1, 'denominations' => [['denomination_id' => $denomination, 'quantity' => 0]]])->assertOk()->assertJsonPath('data.actual_amount', '0.000000');
        $client->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/attempts/'.$attempt.'/confirm', ['type' => 'custodian', 'comments' => 'Cash remains in custody'])->assertOk();
        $client->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/attempts/'.$attempt.'/submit')->assertOk()->assertJsonPath('data.variance_classification', 'balanced');
        [$reviewer, $reviewerClient] = $this->scopedUser($company, ['cash-accounts.cash-counts.review']);
        $reviewerClient->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/review')->assertOk();
        [$approver, $approverClient] = $this->scopedUser($company, ['cash-accounts.cash-counts.approve', 'cash-accounts.handovers.approve']);
        $approverClient->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/approve')->assertOk();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $client->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/close')->assertOk()->assertJsonPath('data.status', 'closed');
        $incoming = User::factory()->create(['status' => 'active']);
        $incoming->companies()->attach($company->id, ['status' => 'active', 'is_owner' => false]);
        $ownerRole = $owner->roles()->firstOrFail();
        $incoming->roles()->attach($ownerRole->id, ['company_id' => $company->id]);
        $handover = $client->postJson('/api/v1/cash-accounts/custodian-handovers', ['cash_account_id' => $account, 'cash_count_id' => $count['id'], 'accepted_attempt_id' => $attempt, 'incoming_custodian_id' => $incoming->id, 'handover_date' => now()->toDateString(), 'reason' => 'Routine cashier handover'])->assertCreated()->json('data');
        $client->postJson('/api/v1/cash-accounts/custodian-handovers/'.$handover['id'].'/confirm', ['type' => 'outgoing', 'comments' => 'Handing over'])->assertOk();
        $incomingClient = $this->actingAs($incoming, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $incomingClient->postJson('/api/v1/cash-accounts/custodian-handovers/'.$handover['id'].'/confirm', ['type' => 'incoming', 'comments' => 'Accepting custody'])->assertOk();
        $approverClient = $this->actingAs($approver, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $approverClient->postJson('/api/v1/cash-accounts/custodian-handovers/'.$handover['id'].'/approve')->assertOk();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $client->postJson('/api/v1/cash-accounts/custodian-handovers/'.$handover['id'].'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame($before, CashMovement::count());
        $this->assertSame(1, CashCountAttempt::where('cash_count_id', $count['id'])->count());
    }

    public function test_variance_requires_disposition_and_posts_then_reverses_linked_adjustment(): void
    {
        Storage::fake('local');
        [$owner, $company, $client, $account, $currency, $assetTitle] = $this->physicalAccount();
        $countReason = $this->createReason($client, 'CASH_COUNT', 'COUNT-VARIANCE');
        $movementReason = $this->createReason($client, 'CASH_MOVEMENT', 'ADJUST-VARIANCE');
        $offset = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'OVERAGE-INCOME', 'name' => 'Cash Overage Income', 'classification' => 'income', 'normal_balance' => 'credit'])->assertCreated()->json('data.id');
        $denomination = $client->postJson('/api/v1/cash-accounts/cash-counts/denominations', ['currency_id' => $currency, 'denomination_type' => 'NOTE', 'face_value' => '25', 'display_label' => 'Twenty Five'])->assertCreated()->json('data.id');
        $count = $client->postJson('/api/v1/cash-accounts/cash-counts', ['cash_account_id' => $account, 'count_type' => 'ROUTINE', 'currency_id' => $currency, 'count_date' => now()->toDateString(), 'cut_off_at' => now()->subMinute()->toIso8601String(), 'counter_id' => $owner->id, 'reason_code_id' => $countReason, 'explanation' => 'Investigate physical overage'])->assertCreated()->json('data');
        $client->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/start')->assertOk();
        $count = $client->getJson('/api/v1/cash-accounts/cash-counts/'.$count['id'])->json('data');
        $attempt = $count['current_attempt']['id'];
        $client->post('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/evidence', ['file' => UploadedFile::fake()->create('variance.pdf', 100, 'application/pdf')])->assertCreated();
        $client->patchJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/attempts/'.$attempt, ['version' => 1, 'denominations' => [['denomination_id' => $denomination, 'quantity' => 1]]])->assertOk();
        $client->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/attempts/'.$attempt.'/confirm', ['type' => 'custodian'])->assertOk();
        $client->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/attempts/'.$attempt.'/submit')->assertOk()->assertJsonPath('data.variance_classification', 'overage');
        [$reviewer, $reviewerClient] = $this->scopedUser($company, ['cash-accounts.cash-counts.review', 'cash-accounts.variances.disposition']);
        $reviewerClient->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/review')->assertOk();
        $reviewerClient->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/disposition', ['disposition' => 'ADJUST_CASH', 'reason' => 'Verified excess cash', 'offset_account_title_id' => $offset, 'reason_code_id' => $movementReason])->assertOk();
        [$approver, $approverClient] = $this->scopedUser($company, ['cash-accounts.cash-counts.approve', 'cash-accounts.adjustments.approve']);
        $approverClient->postJson('/api/v1/cash-accounts/cash-counts/'.$count['id'].'/approve')->assertOk();
        $adjustment = CashCount::whereKey($count['id'])->firstOrFail()->adjustment_id;
        $approverClient->postJson('/api/v1/cash-accounts/cash-adjustments/'.$adjustment.'/approve')->assertOk();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $posted = $client->postJson('/api/v1/cash-accounts/cash-adjustments/'.$adjustment.'/post')->assertOk()->assertJsonPath('data.status', 'posted')->json('data');
        $this->assertNotNull($posted['cash_movement_id']);
        $this->assertSame(1, CashMovement::where('source_record_type', CashMovementDocument::class)->where('source_record_id', $posted['cash_movement_document_id'])->count());
        $client->postJson('/api/v1/cash-accounts/cash-adjustments/'.$adjustment.'/reverse', ['reason' => 'Correction of count disposition'])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertEquals(0.0, (float) CashMovement::where('cash_account_id', $account)->where('movement_status', 'posted')->selectRaw("COALESCE(SUM(CASE WHEN direction = 'increase' THEN amount ELSE -amount END), 0) as total")->value('total'));
    }

    private function physicalAccount(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Owner', 'email' => 'count-owner-'.uniqid().'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Count Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));
        $company = $owner->companies()->firstOrFail();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $asset = $client->postJson('/api/v1/master-registries/account-titles', ['code' => 'CASH-BOX-ASSET', 'name' => 'Cash Box Asset', 'classification' => 'asset', 'normal_balance' => 'debit'])->assertCreated()->json('data.id');
        $type = CashAccountType::where('code', 'CASH_BOX')->firstOrFail();
        $account = $client->postJson('/api/v1/cash-accounts', ['code' => 'COUNT-BOX', 'name' => 'Count Box', 'cash_account_type_id' => $type->id, 'account_title_id' => $asset, 'currency_id' => $company->default_currency_id])->assertCreated()->json('data.id');
        $client->postJson('/api/v1/cash-accounts/'.$account.'/custodians', ['user_id' => $owner->id, 'is_primary' => true, 'effective_from' => now()->toDateString()])->assertCreated();
        $client->postJson('/api/v1/cash-accounts/'.$account.'/activate', ['reason' => 'Ready for physical count'])->assertOk();

        return [$owner, $company, $client, $account, $company->default_currency_id, $asset];
    }

    private function createReason($client, string $domain, string $code): string
    {
        return $client->postJson('/api/v1/master-registries/reason-codes', ['code' => $code, 'name' => $code.' reason', 'domain' => $domain])->assertCreated()->json('data.id');
    }

    private function scopedUser($company, array $permissions): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->companies()->attach($company->id, ['status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Count Role', 'slug' => 'count-role-'.uniqid(), 'status' => 'active']);
        $role->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id')->all());
        $user->roles()->attach($role->id, ['company_id' => $company->id]);

        return [$user, $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id)];
    }
}
