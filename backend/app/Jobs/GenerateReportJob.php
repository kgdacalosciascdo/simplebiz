<?php

namespace App\Jobs;

use App\Exceptions\RegistryConflictException;
use App\Models\ReportRequest;
use App\Services\ReportCompletionService;
use App\Services\ReportsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(
        public readonly int $companyId,
        public readonly string $reportRequestId,
        public readonly string $reportDefinitionId,
        public readonly int $definitionVersion,
        public readonly ?int $userId,
        public readonly ?string $correlationId,
    ) {
        $this->tries = max(1, (int) config('reports.queue.tries', 3));
    }

    public function backoff(): array
    {
        return (array) config('reports.queue.backoff_seconds', [30, 120]);
    }

    public function handle(ReportsService $reports, ReportCompletionService $completion): void
    {
        try {
            $reports->processQueuedRequest((string) $this->companyId, $this->reportRequestId, $this->reportDefinitionId, $this->definitionVersion);
        } catch (\Throwable $exception) {
            $retryable = ! $exception instanceof RegistryConflictException && $this->attempts() < $this->tries;
            $reports->recordQueuedFailure($this->reportRequestId, $exception, $retryable);
            if ($retryable) {
                throw $exception;
            }

            $failedRequest = ReportRequest::with(['definition', 'outputs'])->find($this->reportRequestId);
            if ($failedRequest?->status === 'failed') {
                $completion->failQueuedRequest($failedRequest);
            }

            return;
        }

        $reportRequest = ReportRequest::with(['definition', 'outputs'])->find($this->reportRequestId);
        if ($reportRequest?->status === 'completed') {
            $completion->completeQueuedRequest($reportRequest);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $reports = app(ReportsService::class);
        $reports->recordQueuedFailure($this->reportRequestId, $exception, false);
        $request = ReportRequest::with('definition')->find($this->reportRequestId);
        if ($request?->status === 'failed') {
            app(ReportCompletionService::class)->failQueuedRequest($request);
        }
    }
}
