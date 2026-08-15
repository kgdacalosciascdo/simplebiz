<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CoreFinalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_errors_include_correlation_metadata_and_logout_revokes_session(): void
    {
        $invalid = $this->withHeader('X-Correlation-ID', 'phase16-validation')
            ->postJson('/api/v1/auth/login', []);

        $invalid->assertStatus(422)
            ->assertHeader('X-Correlation-ID', 'phase16-validation')
            ->assertJsonStructure(['message', 'errors', 'meta' => ['correlation_id']])
            ->assertJsonPath('meta.correlation_id', 'phase16-validation');

        $setup = $this->postJson('/api/v1/setup/bootstrap', $this->setupPayload());
        $token = $setup->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_global_search_is_permission_filtered_and_company_scoped(): void
    {
        $first = $this->postJson('/api/v1/setup/bootstrap', $this->setupPayload([
            'email' => 'first-owner@example.com',
            'company_name' => 'First Search Company',
        ]));
        $firstToken = $first->json('data.token');
        $firstCompanyId = $first->json('data.company.id');

        $partner = $this->withToken($firstToken)
            ->withHeader('X-Company-ID', (string) $firstCompanyId)
            ->withHeader('Idempotency-Key', 'phase16-partner-first')
            ->postJson('/api/v1/master-registries/business-partners', [
                'code' => 'FIRST-BP',
                'official_name' => 'First Search Partner',
                'primary_email' => 'first-search@example.test',
            ])
            ->assertCreated();

        $partnerId = $partner->json('data.id');
        $this->withToken($firstToken)
            ->withHeader('X-Company-ID', (string) $firstCompanyId)
            ->getJson('/api/v1/search?q=First%20Search')
            ->assertOk()
            ->assertJsonPath('data.items.0.type', 'business_partner')
            ->assertJsonPath('data.items.0.id', $partnerId)
            ->assertJsonPath('data.items.0.route', '/master-registries/business-partners/'.$partnerId);

        $second = $this->withServerVariables(['REMOTE_ADDR' => '10.16.0.2'])
            ->withHeaders(['X-SimpleBIZ-Device' => 'phase16-second-device', 'Idempotency-Key' => 'phase16-setup-second'])
            ->postJson('/api/v1/setup/bootstrap', $this->setupPayload([
                'email' => 'second-owner@example.com',
                'company_name' => 'Second Search Company',
            ]));
        $second->assertCreated();
        $secondCompanyId = $second->json('data.company.id');

        $this->app['auth']->forgetGuards();
        $this->withToken($second->json('data.token'))
            ->withHeader('X-Company-ID', (string) $secondCompanyId)
            ->postJson('/api/v1/master-registries/business-partners', [
                'code' => 'SECOND-BP',
                'official_name' => 'Second Search Partner',
            ])
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withToken($firstToken)
            ->withHeader('X-Company-ID', (string) $firstCompanyId)
            ->getJson('/api/v1/search?q=Second%20Search')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->app['auth']->forgetGuards();
        $this->withToken($firstToken)
            ->withHeader('X-Company-ID', (string) $secondCompanyId)
            ->getJson('/api/v1/search?q=Second%20Search')
            ->assertNotFound();
    }

    public function test_fresh_company_roles_receive_the_core_search_permission(): void
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', $this->setupPayload());
        $companyId = $setup->json('data.company.id');

        foreach (['business_owner', 'administrator', 'member'] as $systemKey) {
            $role = Role::where('company_id', $companyId)->where('system_key', $systemKey)->firstOrFail();
            $this->assertTrue($role->permissions()->where('key', 'core.search')->exists(), $systemKey.' should receive core.search');
        }
    }

    public function test_notifications_are_company_scoped_readable_idempotent_and_audited(): void
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', $this->setupPayload());
        $token = $setup->json('data.token');
        $companyId = $setup->json('data.company.id');
        $user = User::findOrFail($setup->json('data.user.id'));
        $company = Company::findOrFail($companyId);
        $request = Request::create('/api/v1/notifications', 'GET');
        $request->attributes->set('correlation_id', 'phase16-notification-publish');

        $notification = app(NotificationService::class)->publish(
            $request,
            $company,
            $user,
            'sales.sale.posted',
            'sales',
            'Sale posted',
            'Sale S-000001 was posted.',
            'sale-posted-'.$companyId,
            '/sales/sale-1',
            ['record_type' => 'sale'],
            'info',
            'sale',
            'sale-1',
        );

        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'company_id' => $companyId, 'user_id' => $user->id]);
        $this->withToken($token)
            ->withHeader('X-Company-ID', (string) $companyId)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.items.0.id', $notification->id)
            ->assertJsonPath('data.delivery.state', 'in_app_only');

        $read = $this->withToken($token)
            ->withHeaders(['X-Company-ID' => (string) $companyId, 'X-Correlation-ID' => 'phase16-notification-read', 'Idempotency-Key' => 'phase16-notification-read'])
            ->postJson('/api/v1/notifications/'.$notification->id.'/read', []);
        $read->assertOk()
            ->assertHeader('X-Correlation-ID', 'phase16-notification-read')
            ->assertJsonPath('data.id', $notification->id);
        $this->assertNotNull($read->json('data.read_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'core.notification.read', 'company_id' => $companyId, 'correlation_id' => 'phase16-notification-read']);

        $secondRequest = Request::create('/api/v1/notifications', 'GET');
        app(NotificationService::class)->publish($secondRequest, $company, $user, 'sales.sale.reviewed', 'sales', 'Sale reviewed', 'A sale needs review.', 'sale-reviewed-'.$companyId);
        $this->withToken($token)
            ->withHeaders(['X-Company-ID' => (string) $companyId, 'Idempotency-Key' => 'phase16-notifications-read-all'])
            ->postJson('/api/v1/notifications/read-all', [])
            ->assertOk()
            ->assertJsonPath('data.marked_read', 1);
        $this->assertSame(0, Notification::where('company_id', $companyId)->where('user_id', $user->id)->whereNull('read_at')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'core.notifications.read_all', 'company_id' => $companyId]);
    }

    private function setupPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Business Owner',
            'email' => 'owner@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'company_name' => 'Acme Demo',
            'currency' => 'PHP',
            'timezone' => 'Asia/Manila',
            'locale' => 'en',
        ], $overrides);
    }
}
