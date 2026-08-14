<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportJob;
use App\Models\ReportRequest;
use App\Models\User;
use App\Services\ReportCompletionService;
use App\Services\ReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportsPhase10CTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_request_uses_the_governed_queue_job_and_is_idempotent_on_replay(): void
    {
        [$owner, $company] = $this->ownerContext();
        Storage::fake('local');
        Queue::fake();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $payload = ['definition_key' => 'REP-COL-001', 'parameters' => ['from' => '2026-01-01', 'to' => '2026-01-31'], 'output_type' => 'csv'];

        $response = $client->withHeader('Idempotency-Key', 'phase10c-export')->postJson('/api/v1/reports/requests', $payload)->assertAccepted()->assertJsonPath('data.status', 'queued');
        $requestId = $response->json('data.id');
        Queue::assertPushed(GenerateReportJob::class);
        $job = Queue::pushed(GenerateReportJob::class)->first();
        $job->handle(app(ReportsService::class), app(ReportCompletionService::class));
        $job->handle(app(ReportsService::class), app(ReportCompletionService::class));

        $client->getJson('/api/v1/reports/requests/'.$requestId.'/status')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseCount('report_outputs', 1);
        Storage::disk('local')->assertExists(ReportRequest::findOrFail($requestId)->outputs()->firstOrFail()->storage_path);
    }

    public function test_queued_request_can_be_cancelled_and_a_stale_worker_cannot_publish_output(): void
    {
        [$owner, $company] = $this->ownerContext();
        Queue::fake();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $response = $client->postJson('/api/v1/reports/requests', ['definition_key' => 'REP-COL-001', 'output_type' => 'xlsx'])->assertAccepted()->assertJsonPath('data.status', 'queued');
        $requestId = $response->json('data.id');

        $client->postJson('/api/v1/reports/requests/'.$requestId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        Queue::pushed(GenerateReportJob::class)->first()->handle(app(ReportsService::class), app(ReportCompletionService::class));

        $this->assertDatabaseHas('report_requests', ['id' => $requestId, 'status' => 'cancelled']);
        $this->assertDatabaseCount('report_outputs', 0);
    }

    public function test_scheduled_export_is_queued_and_delivery_waits_for_output_completion(): void
    {
        [$owner, $company] = $this->ownerContext();
        Storage::fake('local');
        Queue::fake();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $schedule = $client->postJson('/api/v1/reports/schedules', ['definition_key' => 'REP-SAL-001', 'name' => 'Queued sales report', 'recurrence' => 'monthly', 'day_of_month' => 1, 'run_time' => '08:00', 'parameters' => ['from' => '2026-01-01', 'to' => '2026-01-31']])->assertCreated()->json('data');

        $client->postJson('/api/v1/reports/schedules/'.$schedule['id'].'/run')->assertOk();
        $occurrence = $client->getJson('/api/v1/reports/schedules/'.$schedule['id'].'/occurrences')->assertOk()->json('data.0');
        $this->assertSame('queued', $occurrence['status']);
        $this->assertNotEmpty($occurrence['report_request_id']);
        Queue::pushed(GenerateReportJob::class)->first()->handle(app(ReportsService::class), app(ReportCompletionService::class));

        $client->getJson('/api/v1/reports/schedules/'.$schedule['id'].'/occurrences')->assertOk()->assertJsonPath('data.0.status', 'completed');
        $client->getJson('/api/v1/reports/deliveries')->assertOk()->assertJsonPath('data.0.status', 'sent');
    }

    public function test_authorized_manual_retry_requeues_a_failed_request_without_changing_definition_version(): void
    {
        [$owner, $company] = $this->ownerContext();
        Queue::fake();
        $client = $this->actingAs($owner, 'sanctum')->withHeader('X-Company-ID', $company->id);
        $response = $client->postJson('/api/v1/reports/requests', ['definition_key' => 'REP-COL-001', 'output_type' => 'csv'])->assertAccepted();
        $request = ReportRequest::findOrFail($response->json('data.id'));
        $request->update(['status' => 'failed', 'failure_code' => 'REPORT_GENERATION_FAILED', 'failure_category' => 'worker', 'failure_message' => 'Temporary worker failure.', 'retryable' => true, 'failed_at' => now(), 'failure_history' => [['code' => 'REPORT_GENERATION_FAILED']]]);

        $client->postJson('/api/v1/reports/requests/'.$request->id.'/retry')->assertOk()->assertJsonPath('data.status', 'queued');
        Queue::pushed(GenerateReportJob::class)->first()->handle(app(ReportsService::class), app(ReportCompletionService::class));

        $this->assertDatabaseHas('report_requests', ['id' => $request->id, 'status' => 'completed', 'definition_version' => 1]);
        $this->assertDatabaseCount('report_outputs', 1);
    }

    private function ownerContext(): array
    {
        $setup = $this->postJson('/api/v1/setup/bootstrap', ['name' => 'Reports Queue Owner', 'email' => 'reports-queue-'.Str::lower(Str::random(8)).'@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'company_name' => 'Reports Queue Demo', 'currency' => 'PHP', 'timezone' => 'Asia/Manila', 'locale' => 'en']);
        $owner = User::findOrFail($setup->json('data.user.id'));

        return [$owner, $owner->companies()->firstOrFail()];
    }
}
