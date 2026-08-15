<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardPhase13Test extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_dashboard_composes_owner_sources_and_context(): void
    {
        [$user, $company] = $this->ownerContext();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);

        $response = $client->getJson('/api/v1/dashboard?period=custom&from=2026-08-01&to=2026-08-15&currency=PHP');

        $response->assertOk()->assertJsonStructure([
            'data' => [
                'context' => ['company', 'branch', 'period', 'as_of', 'currency'],
                'sources',
                'kpis',
                'attention',
                'activity',
                'quick_actions',
                'reports',
                'refresh',
            ],
        ])->assertJsonPath('data.context.company.id', $company->id)
            ->assertJsonPath('data.context.period.preset', 'custom')
            ->assertJsonPath('data.context.period.from', '2026-08-01')
            ->assertJsonPath('data.context.period.to', '2026-08-15')
            ->assertJsonPath('data.context.currency.selected', 'PHP')
            ->assertJsonPath('data.sources.sales.owner', 'MDS-200')
            ->assertJsonPath('data.sources.cash.owner', 'MDS-700');
    }

    public function test_dashboard_rejects_invalid_context_without_loading_sources(): void
    {
        [$user, $company] = $this->ownerContext();
        $client = $this->actingAs($user, 'sanctum')->withHeader('X-Company-ID', $company->id);

        $client->getJson('/api/v1/dashboard?period=custom&from=2026-08-01')->assertStatus(422);
        $client->getJson('/api/v1/dashboard?period=not-a-period')->assertStatus(422);
        $client->getJson('/api/v1/dashboard?currency=USD')->assertStatus(422);
    }

    public function test_dashboard_requires_authentication_and_company_scope(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();

        [$user, $company] = $this->ownerContext();
        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-ID', (string) ($company->id + 9999))
            ->getJson('/api/v1/dashboard')
            ->assertStatus(404);
    }

    private function ownerContext(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', [
            'name' => 'Dashboard Owner',
            'email' => 'dashboard-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'company_name' => 'Dashboard Demo',
            'currency' => 'PHP',
            'timezone' => 'Asia/Manila',
            'locale' => 'en',
        ])->assertCreated();

        $user = User::findOrFail($setup->json('data.user.id'));

        return [$user, $user->companies()->firstOrFail()];
    }
}
