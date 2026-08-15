<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsPhase15Test extends TestCase
{
    use RefreshDatabase;

    public function test_settings_workspace_account_configuration_and_preferences_are_live_and_scoped(): void
    {
        [$token, $companyId] = $this->bootstrap();
        $headers = ['X-Company-ID' => (string) $companyId];

        $this->withToken($token)->withHeaders($headers)->getJson('/api/v1/settings/workspace')
            ->assertOk()
            ->assertJsonPath('data.plan.edition', 'Free')
            ->assertJsonPath('data.destinations.0.key', 'account');

        $this->withToken($token)->withHeaders($headers)->patchJson('/api/v1/settings/account/profile', ['name' => 'Updated Owner'])
            ->assertOk()->assertJsonPath('data.name', 'Updated Owner');
        $this->withToken($token)->withHeaders($headers)->patchJson('/api/v1/settings/account/preferences', ['locale' => 'en', 'timezone' => 'UTC'])
            ->assertOk()->assertJsonPath('data.timezone', 'UTC');
        $this->withToken($token)->withHeaders($headers)->patchJson('/api/v1/settings/company', [
            'name' => 'Configured Company', 'currency' => 'PHP', 'timezone' => 'UTC', 'locale' => 'en', 'fiscal_year_start_month' => 4,
            'date_format' => 'Y-m-d', 'number_format' => 'en-PH', 'paper_size' => 'A4', 'reason' => 'Phase 15 configuration test',
        ])->assertOk();

        $this->withToken($token)->withHeaders($headers)->getJson('/api/v1/settings/configuration')
            ->assertOk()->assertJsonPath('data.effective.currency.value', 'PHP')->assertJsonPath('data.effective.currency.source', 'company');
        $this->withToken($token)->withHeaders($headers)->getJson('/api/v1/settings/modules')->assertOk()->assertJsonPath('data.edition', 'Free');
        $this->withToken($token)->withHeaders($headers)->getJson('/api/v1/settings/notifications')->assertOk()->assertJsonPath('data.mandatory.security', true);
        $this->assertDatabaseHas('configuration_change_sets', ['company_id' => $companyId, 'status' => 'published', 'setting_key' => 'company.profile']);
        $this->assertDatabaseHas('user_preferences', ['company_id' => $companyId, 'locale' => 'en', 'timezone' => 'UTC']);
    }

    public function test_security_sessions_are_visible_without_exposing_token_values(): void
    {
        [$token, $companyId] = $this->bootstrap();
        $user = User::where('email', 'owner@example.com')->firstOrFail();
        $secondToken = $user->createToken('second-device', ['*'], now()->addHour());
        $response = $this->withToken($token)->withHeader('X-Company-ID', (string) $companyId)->getJson('/api/v1/settings/sessions');

        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.token');
        $this->withToken($token)->withHeader('X-Company-ID', (string) $companyId)->deleteJson('/api/v1/settings/sessions/'.$secondToken->accessToken->id)->assertOk()->assertJsonPath('data.terminated', true);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $secondToken->accessToken->id]);
    }

    public function test_scoped_administrative_export_is_audited_and_downloadable(): void
    {
        [$token, $companyId] = $this->bootstrap();
        $headers = ['X-Company-ID' => (string) $companyId, 'Idempotency-Key' => 'settings-export-one'];
        $created = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/settings/exports', ['export_type' => 'administration', 'purpose' => 'Owner review']);

        $created->assertCreated()->assertJsonPath('data.status', 'ready');
        $exportId = $created->json('data.id');
        $this->withToken($token)->withHeader('X-Company-ID', (string) $companyId)->getJson('/api/v1/settings/exports/'.$exportId)->assertOk()->assertJsonPath('data.status', 'ready');
        $download = $this->withToken($token)->withHeader('X-Company-ID', (string) $companyId)->get('/api/v1/settings/exports/'.$exportId.'/download');
        $download->assertOk()->assertHeader('content-type', 'application/json');
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.export.generated', 'company_id' => $companyId]);
    }

    public function test_ownership_transfer_requires_two_party_protected_acceptance(): void
    {
        [$ownerToken, $companyId] = $this->bootstrap();
        $memberRole = Role::where('company_id', $companyId)->where('system_key', 'member')->firstOrFail();
        $invite = $this->withToken($ownerToken)->withHeader('X-Company-ID', (string) $companyId)->postJson('/api/v1/settings/users/invitations', ['email' => 'incoming@example.com', 'role_id' => $memberRole->id]);
        $member = $this->postJson('/api/v1/auth/invitations/accept', ['token' => $invite->json('data.development_token'), 'name' => 'Incoming Owner', 'password' => 'password-123']);
        $memberToken = $member->json('data.token');
        $memberId = $member->json('data.user.id');
        $headers = ['X-Company-ID' => (string) $companyId, 'Idempotency-Key' => 'owner-transfer-request'];

        $transfer = $this->withToken($ownerToken)->withHeaders($headers)->postJson('/api/v1/settings/ownership-transfers', ['user_id' => $memberId, 'current_password' => 'password-123', 'confirmation' => 'TRANSFER OWNERSHIP', 'reason' => 'Owner succession']);
        $transfer->assertCreated()->assertJsonPath('data.status', 'pending');
        $transferId = $transfer->json('data.id');
        $this->actingAs(User::findOrFail($memberId), 'sanctum')->withHeaders(['X-Company-ID' => (string) $companyId, 'Idempotency-Key' => 'owner-transfer-accept'])->postJson('/api/v1/settings/ownership-transfers/'.$transferId.'/accept', ['current_password' => 'password-123', 'confirmation' => 'ACCEPT OWNERSHIP'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('company_user', ['company_id' => $companyId, 'user_id' => $memberId, 'is_owner' => true]);
    }

    private function bootstrap(): array
    {
        $response = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Business Owner', 'email' => 'owner@example.com', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Acme Settings', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);

        return [$response->json('data.token'), $response->json('data.company.id')];
    }
}
