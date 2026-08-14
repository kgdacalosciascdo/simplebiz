<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportsPhase10ATest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_generation_export_snapshot_and_user_preferences_are_governed(): void
    {
        [$owner, $company] = $this->ownerContext();
        Storage::fake('local');
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);

        $catalog = $client->getJson('/api/v1/reports/catalog')->assertOk();
        $this->assertTrue(collect($catalog->json('data'))->contains(fn ($item) => $item['definition_key'] === 'REP-COL-001'));
        $this->assertGreaterThanOrEqual(18, count($catalog->json('data')));
        $this->assertTrue(collect($catalog->json('data'))->contains(fn ($item) => $item['definition_key'] === 'REP-SAL-001'));

        $client->getJson('/api/v1/reports/catalog/REP-COL-001')->assertOk()->assertJsonPath('data.definition.version', 1)->assertJsonPath('data.parameters.0.name', 'from');
        $payload = ['definition_key' => 'REP-COL-001', 'parameters' => ['from' => '2026-01-01', 'to' => '2026-01-31'], 'output_type' => 'display'];
        $first = $client->withHeader('Idempotency-Key', 'reports-phase10a-display')->postJson('/api/v1/reports/requests', $payload)->assertCreated()->assertJsonPath('data.status', 'completed');
        $requestId = $first->json('data.id');
        $first->assertJsonPath('data.outputs.0.format', 'display')->assertJsonPath('data.outputs.0.result_data.meta.freshness_state', 'current');
        $replay = $client->withHeader('Idempotency-Key', 'reports-phase10a-display')->postJson('/api/v1/reports/requests', $payload)->assertCreated();
        $this->assertSame($requestId, $replay->json('data.id'));
        $this->assertDatabaseCount('report_requests', 1);

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $response = $client->withHeader('Idempotency-Key', 'reports-phase10a-'.$format)->postJson('/api/v1/reports/requests', ['definition_key' => 'REP-COL-001', 'parameters' => ['from' => '2026-01-01', 'to' => '2026-01-31'], 'output_type' => $format])->assertCreated()->assertJsonPath('data.status', 'completed');
            $output = $response->json('data.outputs.0');
            $this->assertNotEmpty($output['storage_path']);
            Storage::disk('local')->assertExists($output['storage_path']);
            $client->getJson('/api/v1/reports/outputs/'.$output['id'])->assertOk()->assertJsonPath('data.format', $format);
            $client->get('/api/v1/reports/outputs/'.$output['id'].'/download')->assertOk();
        }

        $outputId = $first->json('data.outputs.0.id');
        $snapshot = $client->postJson('/api/v1/reports/outputs/'.$outputId.'/snapshot', ['title' => 'January receipts'])->assertCreated();
        $this->assertSame(1, $snapshot->json('data.definition_version'));
        $this->assertSame('2026-01-01', $snapshot->json('data.parameters.from'));
        $client->postJson('/api/v1/reports/favorites', ['definition_key' => 'REP-COL-001', 'parameters' => ['from' => '2026-01-01']])->assertCreated();
        $favorite = $client->getJson('/api/v1/reports/favorites')->assertOk()->json('data.0.id');
        $client->deleteJson('/api/v1/reports/favorites/'.$favorite)->assertStatus(204);
        $client->postJson('/api/v1/reports/saved-views', ['definition_key' => 'REP-COL-001', 'name' => 'January receipts view', 'parameters' => ['from' => '2026-01-01']])->assertCreated();
        $client->getJson('/api/v1/reports/saved-views')->assertOk()->assertJsonPath('data.0.name', 'January receipts view');
        $client->postJson('/api/v1/reports/outputs/'.$outputId.'/print')->assertOk();
        $client->getJson('/api/v1/reports/history')->assertOk()->assertJsonCount(4, 'data');
        $this->assertDatabaseHas('report_access_audits', ['action' => 'exported', 'company_id' => $company->id]);
    }

    public function test_report_parameters_and_company_scope_are_enforced(): void
    {
        [$owner, $company] = $this->ownerContext();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $client->postJson('/api/v1/reports/requests', ['definition_key' => 'REP-COL-001', 'parameters' => ['from' => '2026-02-01', 'to' => '2026-01-01']])->assertStatus(409);
        $client->postJson('/api/v1/reports/requests', ['definition_key' => 'REP-COL-001', 'parameters' => ['unsupported_filter' => 'x']])->assertStatus(409);

        $request = $client->postJson('/api/v1/reports/requests', ['definition_key' => 'REP-COL-001'])->assertCreated();
        $outputId = $request->json('data.outputs.0.id');
        $client->withHeader('X-Company-ID', (string) ($company->id + 9999))->getJson('/api/v1/reports/outputs/'.$outputId)->assertStatus(404);
    }

    private function ownerContext(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Reports Owner', 'email' => 'reports-'.Str::lower(Str::random(8)).'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Reports Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));

        return [$owner, $owner->companies()->firstOrFail()];
    }
}
