<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoreFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_status_and_correlation_id_are_available(): void
    {
        $response = $this->withHeaders(['X-Correlation-ID' => 'unsafe value'])->getJson('/api/v1/setup/status');

        $response->assertOk()->assertJsonPath('data.required', true);
        $this->assertNotSame('unsafe value', $response->headers->get('X-Correlation-ID'));
    }

    public function test_initial_setup_is_atomic_and_only_available_once(): void
    {
        $payload = $this->setupPayload();
        $first = $this->withHeaders(['Idempotency-Key' => 'setup-once'])->postJson('/api/v1/setup/bootstrap', $payload);
        $first->assertCreated()->assertJsonPath('data.company.setup_status', 'completed');
        $this->assertDatabaseHas('companies', ['name' => 'Acme Demo']);
        $this->assertDatabaseHas('company_user', ['is_owner' => true]);
        $this->assertDatabaseHas('roles', ['system_key' => 'business_owner', 'is_protected' => true]);

        $second = $this->withHeaders(['Idempotency-Key' => 'setup-second'])->postJson('/api/v1/setup/bootstrap', $payload);
        $second->assertStatus(409);
    }

    public function test_idempotent_setup_replays_a_safe_result_without_a_token(): void
    {
        $payload = $this->setupPayload();
        $first = $this->withHeaders(['Idempotency-Key' => 'setup-replay'])->postJson('/api/v1/setup/bootstrap', $payload);
        $first->assertCreated();

        $replay = $this->withHeaders(['Idempotency-Key' => 'setup-replay'])->postJson('/api/v1/setup/bootstrap', $payload);
        $replay->assertCreated()->assertHeader('Idempotent-Replay', 'true')->assertJsonMissingPath('data.token');
    }

    public function test_login_current_user_and_company_scope_are_protected(): void
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', $this->setupPayload());
        $token = $setup->json('data.token');
        $companyId = $setup->json('data.company.id');

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.email', 'owner@example.com');
        $this->withToken($token)->withHeader('X-Company-ID', (string) $companyId)->getJson('/api/v1/context/company')->assertOk()->assertJsonPath('data.id', $companyId);
    }

    public function test_invitation_acceptance_creates_a_non_owner_membership(): void
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', $this->setupPayload());
        $token = $setup->json('data.token');
        $companyId = $setup->json('data.company.id');
        $roleId = Role::where('company_id', $companyId)->where('system_key', 'member')->value('id');

        $invite = $this->withToken($token)->withHeader('X-Company-ID', (string) $companyId)->withHeader('Idempotency-Key', 'invite-one')->postJson('/api/v1/settings/users/invitations', ['email' => 'member@example.com', 'role_id' => $roleId]);
        $invite->assertCreated();
        $invitationToken = $invite->json('data.development_token');

        $accepted = $this->postJson('/api/v1/auth/invitations/accept', ['token' => $invitationToken, 'name' => 'Member User', 'password' => 'password-123']);
        $accepted->assertOk();
        $memberId = $accepted->json('data.user.id');
        $this->assertDatabaseHas('company_user', ['company_id' => $companyId, 'user_id' => $memberId, 'is_owner' => false, 'status' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invitation.accepted', 'company_id' => $companyId]);
    }

    public function test_protected_owner_cannot_be_deactivated_and_member_cannot_edit_company(): void
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', $this->setupPayload());
        $ownerToken = $setup->json('data.token');
        $companyId = $setup->json('data.company.id');
        $ownerId = $setup->json('data.user.id');
        $roleId = Role::where('company_id', $companyId)->where('system_key', 'member')->value('id');
        $invite = $this->withToken($ownerToken)->withHeader('X-Company-ID', (string) $companyId)->postJson('/api/v1/settings/users/invitations', ['email' => 'member@example.com', 'role_id' => $roleId]);
        $this->flushHeaders();
        $member = $this->postJson('/api/v1/auth/invitations/accept', ['token' => $invite->json('data.development_token'), 'name' => 'Member User', 'password' => 'password-123']);
        $this->assertFalse(User::find($member->json('data.user.id'))->hasPermission('settings.company.edit', $companyId));
        $memberUser = User::findOrFail($member->json('data.user.id'));
        $this->actingAs($memberUser, 'sanctum')->getJson('/api/v1/auth/me')->assertJsonPath('data.user.email', 'member@example.com');

        $this->flushHeaders();
        $this->withToken($ownerToken)->withHeader('X-Company-ID', (string) $companyId)->patchJson("/api/v1/settings/users/{$ownerId}/status/deactivated")->assertForbidden();
        $this->actingAs($memberUser, 'sanctum')->withHeader('X-Company-ID', (string) $companyId)->patchJson('/api/v1/settings/company', ['name' => 'Not Allowed', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en'])->assertForbidden();
    }

    public function test_validation_errors_use_the_api_envelope(): void
    {
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422)->assertJsonStructure(['message', 'errors']);
    }

    private function setupPayload(): array
    {
        return ['name' => 'Business Owner', 'email' => 'owner@example.com', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Acme Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en'];
    }
}
