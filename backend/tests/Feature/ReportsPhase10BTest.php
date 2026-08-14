<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportsPhase10BTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_surfaces_are_source_scoped_and_schedule_outputs_can_be_packed(): void
    {
        [$owner, $company] = $this->ownerContext();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);

        $catalog = $client->getJson('/api/v1/reports/catalog')->assertOk()->json('data');
        $this->assertTrue(collect($catalog)->pluck('definition_key')->contains('REP-SAL-001'));
        $this->assertTrue(collect($catalog)->pluck('definition_key')->contains('REP-CAS-001'));
        $client->getJson('/api/v1/reports/analytics')->assertOk()->assertJsonPath('data.0.analytics_key', 'ANL-MGT-001');

        $schedule = $client->postJson('/api/v1/reports/schedules', [
            'definition_key' => 'REP-SAL-001',
            'name' => 'Monthly sales register',
            'recurrence' => 'monthly',
            'day_of_month' => 1,
            'run_time' => '08:00',
            'parameters' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
        ])->assertCreated()->json('data');
        $this->assertSame('active', $schedule['status']);

        $client->postJson('/api/v1/reports/schedules/'.$schedule['id'].'/run')->assertOk();
        $occurrence = $client->getJson('/api/v1/reports/schedules/'.$schedule['id'].'/occurrences')->assertOk()->json('data.0');
        $this->assertSame('completed', $occurrence['status']);
        $this->assertNotEmpty($occurrence['report_output_id']);
        $client->getJson('/api/v1/reports/deliveries')->assertOk()->assertJsonPath('data.0.status', 'sent');

        $pack = $client->postJson('/api/v1/reports/packs', ['name' => 'January management pack'])->assertCreated()->json('data');
        $client->postJson('/api/v1/reports/packs/'.$pack['id'].'/items', ['report_output_id' => $occurrence['report_output_id']])->assertOk();
        $client->postJson('/api/v1/reports/packs/'.$pack['id'].'/generate')->assertOk()->assertJsonPath('data.status', 'generated');
        $client->postJson('/api/v1/reports/packs/'.$pack['id'].'/review')->assertOk()->assertJsonPath('data.status', 'reviewed');
        $client->postJson('/api/v1/reports/packs/'.$pack['id'].'/publish')->assertOk()->assertJsonPath('data.status', 'published');
        $client->postJson('/api/v1/reports/schedules/'.$schedule['id'].'/pause')->assertOk()->assertJsonPath('data.status', 'paused');

        $client->getJson('/api/v1/reports/definitions')->assertOk()->assertJsonCount(18, 'data');
    }

    public function test_sales_source_report_and_compare_preserve_company_scope(): void
    {
        [$owner, $company] = $this->ownerContext();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);

        $client->postJson('/api/v1/reports/compare', [
            'definition_key' => 'REP-SAL-001',
            'primary_parameters' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
            'comparison_parameters' => ['from' => '2025-01-01', 'to' => '2025-01-31'],
        ])->assertOk()->assertJsonPath('data.comparability.same_definition_version', true);
    }

    private function ownerContext(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Reports Completion Owner', 'email' => 'reports-completion-'.Str::lower(Str::random(8)).'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Reports Completion Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));

        return [$owner, $owner->companies()->firstOrFail()];
    }
}
