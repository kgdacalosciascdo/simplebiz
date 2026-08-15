<?php

namespace App\Services;

use App\Events\ReportLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Jobs\GenerateReportJob;
use App\Models\Company;
use App\Models\Expense;
use App\Models\InventoryBalance;
use App\Models\PayableOpenItem;
use App\Models\ReceivableOpenItem;
use App\Models\ReportAccessAudit;
use App\Models\ReportCategory;
use App\Models\ReportDefinition;
use App\Models\ReportFavorite;
use App\Models\ReportOutput;
use App\Models\ReportRequest;
use App\Models\ReportSavedView;
use App\Models\ReportSnapshot;
use App\Models\ReportSourceContract;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ReportsService
{
    public function __construct(
        private readonly CollectionsService $collections,
        private readonly SalesService $sales,
        private readonly PurchasingService $purchases,
        private readonly PaymentCompletionService $payments,
        private readonly InventoryCompletionService $inventory,
        private readonly ExpenseCompletionService $expenseCompletion,
        private readonly ExpenseService $expenses,
        private readonly CashPositionService $cashPositions,
        private readonly ReportExportService $exports,
    ) {}

    public function catalog(Company $company, Request $request): array
    {
        $user = $request->user();
        $query = ReportDefinition::query()->with('category')->where('status', 'published')->where(function ($q) {
            $q->whereNull('effective_from')->orWhere('effective_from', '<=', now());
        })->where(function ($q) {
            $q->whereNull('effective_to')->orWhere('effective_to', '>', now());
        });
        $query->when($request->filled('q'), function ($q) use ($request) {
            $term = '%'.mb_strtolower($request->string('q')->toString()).'%';
            $q->where(function ($inner) use ($term) {
                $inner->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(description) LIKE ?', [$term])->orWhereRaw('LOWER(business_question) LIKE ?', [$term])->orWhereRaw('LOWER(source_owner_module) LIKE ?', [$term]);
            });
        });
        $query->when($request->filled('category'), fn ($q) => $q->whereHas('category', fn ($category) => $category->where('code', $request->string('category')->toString())));
        $definitions = $query->orderBy('category_id')->orderBy('name')->get();
        $favorites = $user ? ReportFavorite::where('company_id', $company->id)->where('user_id', $user->id)->pluck('report_definition_id')->all() : [];
        $recent = $user ? ReportRequest::where('company_id', $company->id)->where('user_id', $user->id)->latest()->limit(25)->pluck('report_definition_id')->all() : [];
        $definitions = $definitions->filter(fn (ReportDefinition $definition) => $this->canAccess($definition, $user, $company->id))->values();
        $items = $definitions->map(fn (ReportDefinition $definition) => $this->definitionSummary($definition, in_array($definition->id, $favorites, true), in_array($definition->id, $recent, true)))->all();

        return [$items, ['categories' => ReportCategory::where('status', 'active')->orderBy('display_order')->get(['code', 'name', 'description']), 'count' => count($items)]];
    }

    /**
     * Read-only MDS-900 source projection for the MDS-100 Dashboard.
     * The governed analytics implementation remains the owner of the metrics.
     */
    public function businessPerformanceForDashboard(Company $company, Request $request): array
    {
        return $this->businessPerformance($company, $request);
    }

    public function definition(Company $company, Request $request, string $key, ?int $version = null): array
    {
        $definition = $this->resolveDefinition($key, $version);
        $this->assertAccess($definition, $request);
        $contracts = ReportSourceContract::whereIn('contract_key', $definition->source_contract_keys ?? [])->where('status', 'published')->get();

        return ['definition' => $this->definitionSummary($definition), 'category' => $definition->category, 'parameters' => $definition->parameters->map(fn ($parameter) => ['name' => $parameter->name, 'type' => $parameter->type, 'label' => $parameter->label, 'required' => $parameter->required, 'default' => $parameter->default_value, 'valid_source' => $parameter->valid_source, 'dependencies' => $parameter->dependencies, 'multi_select' => $parameter->multi_select, 'authorization' => $parameter->authorization, 'validation' => $parameter->validation, 'display_order' => $parameter->display_order])->values(), 'columns' => $definition->columns->map(fn ($column) => ['key' => $column->column_key, 'label' => $column->label, 'type' => $column->value_type, 'format' => $column->format, 'visible' => $column->visible, 'sortable' => $column->sortable, 'groupable' => $column->groupable, 'totalable' => $column->totalable, 'sensitivity' => $column->sensitivity, 'source_field' => $column->source_field, 'display_order' => $column->display_order])->values(), 'source_contracts' => $contracts, 'versioning' => ['definition_key' => $definition->definition_key, 'version' => $definition->version, 'status' => $definition->status, 'published_at' => $definition->published_at, 'supersedes_id' => $definition->supersedes_id]];
    }

    public function generate(Company $company, Request $request, array $payload): ReportRequest
    {
        $definition = $this->resolveDefinition((string) $payload['definition_key'], isset($payload['definition_version']) ? (int) $payload['definition_version'] : null);
        $this->assertAccess($definition, $request);
        $outputType = $payload['output_type'] ?? 'display';
        if (! in_array($outputType, ['display', 'pdf', 'xlsx', 'csv'], true)) {
            throw new RegistryConflictException('The requested report output format is not supported.');
        }
        if (($definition->output_capabilities[$outputType] ?? false) !== true) {
            throw new RegistryConflictException('This report definition does not permit the requested output format.');
        }
        $parameters = $this->validateParameters($definition, (array) ($payload['parameters'] ?? []), $company);
        $idempotency = $request->header('Idempotency-Key');
        if ($idempotency) {
            $existing = ReportRequest::where('company_id', $company->id)->where('user_id', $request->user()?->id)->where('idempotency_identity', $idempotency)->first();
            if ($existing) {
                if ($existing->definition_version !== $definition->version || $existing->report_definition_id !== $definition->id || $existing->output_type !== $outputType || $existing->parameters !== $parameters) {
                    throw new RegistryConflictException('This Idempotency-Key was already used for a different report request.');
                }

                return $existing->load(['definition', 'outputs']);
            }
        }
        $executionMode = $this->shouldQueue($outputType, (bool) ($payload['_force_async'] ?? false)) ? 'async' : 'sync';
        $context = array_merge($this->context($company, $request, $parameters), (array) ($payload['_context'] ?? []));
        $reportRequest = ReportRequest::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'report_definition_id' => $definition->id, 'definition_version' => $definition->version, 'user_id' => $request->user()?->id, 'parameters' => $parameters, 'context' => $context, 'output_type' => $outputType, 'status' => 'requested', 'execution_mode' => $executionMode, 'as_of_date' => $parameters['as_of'] ?? $parameters['to'] ?? now($company->timezone)->toDateString(), 'correlation_id' => $request->attributes->get('correlation_id') ?: (string) Str::uuid(), 'idempotency_identity' => $idempotency, 'requested_at' => now()]);
        $this->lifecycle('EVT-RPT-001', $company, $reportRequest, $definition, $request);
        $this->auditAccess($company, $request, $reportRequest, null, $definition, 'requested', null, ['parameters' => $this->safeParameters($parameters)]);

        try {
            $reportRequest->update(['status' => 'validated', 'validated_at' => now()]);
            $this->lifecycle('EVT-RPT-002', $company, $reportRequest, $definition, $request);
            if ($executionMode === 'async') {
                $reportRequest->update(['status' => 'queued', 'queued_at' => now(), 'retryable' => false]);
                $this->lifecycle('EVT-RPT-003', $company, $reportRequest, $definition, $request);
                GenerateReportJob::dispatch($company->id, $reportRequest->id, $definition->id, $definition->version, $request->user()?->id, $reportRequest->correlation_id);

                return $reportRequest->refresh()->load(['definition', 'outputs']);
            }

            return $this->processRequest($company, $request, $reportRequest, $definition, $parameters, $outputType);
        } catch (\Throwable $exception) {
            if (! in_array($reportRequest->fresh()->status, ['failed', 'completed', 'cancelled'], true)) {
                $this->recordFailure($company, $request, $reportRequest, $definition, $outputType, $exception, false);
            }
            if ($exception instanceof RegistryConflictException) {
                throw $exception;
            }
            throw new RegistryConflictException('The report could not be generated. The failed request was retained for status review.', ['request_id' => $reportRequest->id]);
        }
    }

    public function processQueuedRequest(string $companyId, string $requestId, string $definitionId, int $definitionVersion): void
    {
        $claimed = DB::transaction(function () use ($companyId, $requestId, $definitionId, $definitionVersion) {
            $reportRequest = ReportRequest::where('company_id', $companyId)->whereKey($requestId)->lockForUpdate()->firstOrFail();
            if (in_array($reportRequest->status, ['completed', 'cancelled'], true)) {
                return false;
            }
            $staleProcessing = $reportRequest->status === 'processing' && (! $reportRequest->started_at || $reportRequest->started_at->lte(now()->subSeconds((int) config('reports.queue.stale_after_seconds', 900))));
            if ($reportRequest->status === 'processing' && ! $staleProcessing) {
                return false;
            }
            if ($reportRequest->status !== 'queued' && ! $staleProcessing) {
                return false;
            }
            if ($reportRequest->report_definition_id !== $definitionId || (int) $reportRequest->definition_version !== $definitionVersion) {
                throw new RegistryConflictException('The queued report definition version is no longer compatible.');
            }
            $reportRequest->update(['status' => 'processing', 'started_at' => now(), 'last_attempt_at' => now(), 'queue_attempts' => $reportRequest->queue_attempts + 1]);

            return true;
        });
        if (! $claimed) {
            return;
        }

        $company = Company::findOrFail($companyId);
        $reportRequest = ReportRequest::where('company_id', $company->id)->whereKey($requestId)->with('definition')->firstOrFail();
        $definition = ReportDefinition::whereKey($definitionId)->where('version', $definitionVersion)->firstOrFail();
        $actor = $reportRequest->user_id ? User::find($reportRequest->user_id) : null;
        $workerRequest = $this->workerRequest($company, $actor, $reportRequest->correlation_id);
        $this->assertAccess($definition, $workerRequest);
        $this->processRequest($company, $workerRequest, $reportRequest, $definition, (array) $reportRequest->parameters, $reportRequest->output_type, true);
    }

    public function recordQueuedFailure(string $requestId, \Throwable $exception, bool $retryable): void
    {
        $reportRequest = ReportRequest::with('definition')->find($requestId);
        if (! $reportRequest || in_array($reportRequest->status, ['completed', 'cancelled'], true)) {
            return;
        }
        $company = Company::find($reportRequest->company_id);
        $definition = $reportRequest->definition;
        if (! $company || ! $definition) {
            return;
        }
        $actor = $reportRequest->user_id ? User::find($reportRequest->user_id) : null;
        $request = $this->workerRequest($company, $actor, $reportRequest->correlation_id);
        $this->recordFailure($company, $request, $reportRequest, $definition, $reportRequest->output_type, $exception, $retryable);
        if ($retryable) {
            $reportRequest->refresh()->update(['status' => 'queued', 'queued_at' => now(), 'failed_at' => null]);
        }
    }

    public function cancel(Company $company, Request $request, string $id): ReportRequest
    {
        $this->require($request, $company, 'reports.requests.cancel');
        $reportRequest = DB::transaction(function () use ($company, $request, $id) {
            $reportRequest = ReportRequest::where('company_id', $company->id)->whereKey($id)->with('definition')->lockForUpdate()->firstOrFail();
            if (! in_array($reportRequest->status, ['requested', 'validated', 'queued'], true) || $reportRequest->execution_mode !== 'async') {
                throw new RegistryConflictException('Only queued report requests can be cancelled.');
            }
            $reportRequest->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $request->user()?->id, 'retryable' => false]);

            return $reportRequest;
        });
        $this->auditAccess($company, $request, $reportRequest, null, $reportRequest->definition, 'cancelled', $reportRequest->output_type, []);

        return $reportRequest->fresh()->load(['definition', 'outputs']);
    }

    public function retry(Company $company, Request $request, string $id): ReportRequest
    {
        $this->require($request, $company, 'reports.requests.retry');
        $reportRequest = DB::transaction(function () use ($company, $request, $id) {
            $reportRequest = ReportRequest::where('company_id', $company->id)->whereKey($id)->with('definition')->lockForUpdate()->firstOrFail();
            if ($reportRequest->status !== 'failed' || ! $reportRequest->retryable) {
                throw new RegistryConflictException('Only retryable failed report requests can be retried.');
            }
            $this->assertAccess($reportRequest->definition, $request);
            $reportRequest->update(['status' => 'queued', 'execution_mode' => 'async', 'queued_at' => now(), 'retryable' => false, 'failure_code' => null, 'failure_category' => null, 'failure_message' => null, 'failed_at' => null]);

            return $reportRequest;
        });
        $this->lifecycle('EVT-RPT-003', $company, $reportRequest, $reportRequest->definition, $request);
        $this->auditAccess($company, $request, $reportRequest, null, $reportRequest->definition, 'retried', $reportRequest->output_type, ['queue_attempts' => $reportRequest->queue_attempts]);
        GenerateReportJob::dispatch($company->id, $reportRequest->id, $reportRequest->report_definition_id, $reportRequest->definition_version, $reportRequest->user_id, $reportRequest->correlation_id);

        return $reportRequest->fresh()->load(['definition', 'outputs']);
    }

    private function shouldQueue(string $outputType, bool $forceAsync): bool
    {
        return (bool) config('reports.queue.enabled', true)
            && ($forceAsync || in_array($outputType, (array) config('reports.queue.async_output_types', []), true));
    }

    private function processRequest(Company $company, Request $request, ReportRequest $reportRequest, ReportDefinition $definition, array $parameters, string $outputType, bool $queued = false): ReportRequest
    {
        $output = null;
        try {
            if (! $queued) {
                $reportRequest->update(['status' => 'processing', 'started_at' => now()]);
            }
            $raw = $this->sourceRows($definition, $company, $request, $parameters);
            $rows = $this->sortRows($raw['rows'], $parameters, $definition);
            $page = max(1, (int) ($parameters['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($parameters['per_page'] ?? 25)));
            $totalRows = count($rows);
            $pagedRows = array_values(array_slice($rows, ($page - 1) * $perPage, $perPage));
            $freshness = $parameters['as_of'] ?? null ? 'snapshot' : ($raw['freshness_state'] ?? 'current');
            $result = ['columns' => $definition->columns_schema ?? [], 'rows' => $pagedRows, 'totals' => $this->totals($rows, $definition), 'meta' => ['definition_key' => $definition->definition_key, 'definition_version' => $definition->version, 'title' => $definition->name, 'description' => $definition->description, 'source_owner_module' => $definition->source_owner_module, 'source_contracts' => $definition->source_contract_keys, 'status_basis' => $definition->status_basis, 'sign_convention' => $definition->sign_convention, 'freshness_state' => $freshness, 'source_as_of_at' => ($raw['source_as_of_at'] ?? now())->toISOString(), 'as_of_date' => $parameters['as_of'] ?? $parameters['to'] ?? now($company->timezone)->toDateString(), 'currency_context' => $raw['currency_context'] ?? 'Amounts remain separated by source currency.', 'context' => $reportRequest->context, 'parameters' => $parameters, 'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $totalRows, 'last_page' => max(1, (int) ceil($totalRows / $perPage))]]];

            // A cancelled queued request is never allowed to publish a late output.
            $reportRequest->refresh();
            if ($reportRequest->status === 'cancelled') {
                return $reportRequest->load(['definition', 'outputs']);
            }

            $output = ReportOutput::where('report_request_id', $reportRequest->id)->where('format', $outputType)->first();
            if (! $output) {
                $retentionExpiresAt = now()->addDays((int) config('reports.retention_days', 30));
                $output = ReportOutput::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'report_request_id' => $reportRequest->id, 'report_definition_id' => $definition->id, 'definition_version' => $definition->version, 'format' => $outputType, 'status' => 'available', 'result_data' => $outputType === 'display' ? $result : null, 'result_meta' => $result['meta'], 'retention_expires_at' => $retentionExpiresAt, 'expires_at' => $retentionExpiresAt]);
                if ($outputType !== 'display') {
                    $output = $this->exports->write($output, $result, $definition->name, $definition->definition_key, $definition->version);
                }
            }

            $reportRequest->refresh();
            if ($reportRequest->status === 'cancelled') {
                if ($output->storage_path) {
                    Storage::disk($output->storage_disk ?: 'local')->delete($output->storage_path);
                }
                $output->delete();

                return $reportRequest->load(['definition', 'outputs']);
            }
            $reportRequest->update(['status' => 'completed', 'source_as_of_at' => Carbon::parse($result['meta']['source_as_of_at']), 'freshness_state' => $freshness, 'completed_at' => now(), 'result_count' => $totalRows, 'retryable' => false]);
            $this->lifecycle('EVT-RPT-004', $company, $reportRequest, $definition, $request);
            $this->auditAccess($company, $request, $reportRequest, $output, $definition, 'completed', $outputType, ['result_count' => $totalRows, 'freshness_state' => $freshness]);

            return $reportRequest->refresh()->load(['definition', 'outputs']);
        } catch (\Throwable $exception) {
            if (! $queued) {
                $this->recordFailure($company, $request, $reportRequest, $definition, $outputType, $exception, false);
            }
            if ($output && $output->exists && $reportRequest->fresh()->status !== 'completed') {
                if ($output->storage_path) {
                    Storage::disk($output->storage_disk ?: 'local')->delete($output->storage_path);
                }
                $output->delete();
            }
            throw $exception;
        }
    }

    private function recordFailure(Company $company, Request $request, ReportRequest $reportRequest, ReportDefinition $definition, string $outputType, \Throwable $exception, bool $retryable): void
    {
        $reportRequest->refresh();
        if (in_array($reportRequest->status, ['completed', 'cancelled'], true)) {
            return;
        }
        $category = $exception instanceof RegistryConflictException ? 'validation' : 'worker';
        $message = $exception instanceof RegistryConflictException ? Str::limit($exception->getMessage(), 500) : 'The report could not be generated by the reporting worker.';
        $history = $reportRequest->failure_history ?? [];
        $history[] = ['at' => now()->toISOString(), 'category' => $category, 'code' => 'REPORT_GENERATION_FAILED', 'message' => $message];
        $reportRequest->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => 'REPORT_GENERATION_FAILED', 'failure_category' => $category, 'failure_message' => $message, 'retryable' => $retryable, 'failure_history' => array_slice($history, -10)]);
        if (! $retryable) {
            $this->lifecycle('EVT-RPT-005', $company, $reportRequest, $definition, $request);
            $this->auditAccess($company, $request, $reportRequest, null, $definition, 'failed', $outputType, ['failure_code' => 'REPORT_GENERATION_FAILED', 'failure_category' => $category]);
        }
    }

    private function workerRequest(Company $company, ?User $actor, ?string $correlationId): Request
    {
        $request = Request::create('/api/v1/reports/requests', 'POST');
        $request->setUserResolver(fn () => $actor);
        $request->attributes->set('company', $company);
        $request->attributes->set('correlation_id', $correlationId ?: (string) Str::uuid());

        return $request;
    }

    public function request(Company $company, Request $request, string $id): ReportRequest
    {
        return ReportRequest::where('company_id', $company->id)->whereKey($id)->with(['definition', 'outputs'])->firstOrFail();
    }

    public function output(Company $company, Request $request, string $id): ReportOutput
    {
        $output = ReportOutput::where('company_id', $company->id)->whereKey($id)->with(['definition', 'request'])->firstOrFail();
        $this->assertAccess($output->definition, $request);

        return $output;
    }

    public function download(Company $company, Request $request, string $id)
    {
        $output = $this->output($company, $request, $id);
        if ($output->purged_at || ($output->retention_expires_at && $output->retention_expires_at->isPast() && ! $output->legal_hold) || ! $output->storage_path || ! Storage::disk($output->storage_disk ?: 'local')->exists($output->storage_path)) {
            throw new RegistryConflictException('This report output has expired or is unavailable. Generate it again or use a retained snapshot.');
        }
        $output->update(['downloaded_at' => now()]);
        $this->auditAccess($company, $request, $output->request, $output, $output->definition, 'exported', $output->format, ['filename' => data_get($output->result_meta, 'filename')]);
        $this->lifecycle('EVT-RPT-008', $company, $output->request, $output->definition, $request);

        return Storage::disk($output->storage_disk ?: 'local')->download($output->storage_path, data_get($output->result_meta, 'filename', 'simplebiz-report.'.$output->format), ['Content-Type' => $output->content_type]);
    }

    public function print(Company $company, Request $request, string $id): ReportOutput
    {
        $output = $this->output($company, $request, $id);
        $this->auditAccess($company, $request, $output->request, $output, $output->definition, 'printed', 'print', []);
        $this->lifecycle('EVT-RPT-009', $company, $output->request, $output->definition, $request);

        return $output;
    }

    public function drillDown(Company $company, Request $request, string $id, array $input): array
    {
        $output = $this->output($company, $request, $id);
        $recordId = $input['record_id'] ?? null;
        if (! $recordId) {
            throw new RegistryConflictException('A source record id is required for drill-down.');
        }
        $routes = ['sales' => '/sales', 'sales-accounts' => '/sales', 'collections' => '/collections/receipts/'.$recordId, 'purchases' => '/purchases', 'payments' => '/payments', 'inventory' => '/inventory', 'cash-accounts' => '/cash-accounts', 'expenses' => '/expenses'];
        $target = ['company_id' => $company->id, 'source_module' => $output->definition->source_owner_module, 'record_id' => (string) $recordId, 'route' => $routes[$output->definition->source_owner_module] ?? null, 'parameters' => $output->request->parameters, 'definition_key' => $output->definition->definition_key, 'definition_version' => $output->definition_version];
        $this->auditAccess($company, $request, $output->request, $output, $output->definition, 'drilldown', null, ['record_id' => (string) $recordId]);
        $this->lifecycle('EVT-RPT-007', $company, $output->request, $output->definition, $request);

        return $target;
    }

    public function snapshot(Company $company, Request $request, string $id, ?string $title = null): ReportSnapshot
    {
        $output = $this->output($company, $request, $id);
        if (! $output->result_data) {
            throw new RegistryConflictException('Only a Display Report result can be retained as a snapshot.');
        }
        $snapshot = ReportSnapshot::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'report_output_id' => $output->id, 'report_request_id' => $output->report_request_id, 'report_definition_id' => $output->report_definition_id, 'definition_version' => $output->definition_version, 'status' => 'archived', 'title' => $title ?: $output->definition->name.' — '.now()->toDateString(), 'parameters' => $output->request->parameters, 'context' => $output->request->context, 'as_of_date' => $output->request->as_of_date, 'source_as_of_at' => $output->request->source_as_of_at, 'freshness_state' => $output->request->freshness_state, 'result_data' => $output->result_data, 'metadata' => $output->result_meta, 'created_by' => $request->user()?->id, 'archived_at' => now()]);
        $this->auditAccess($company, $request, $output->request, $output, $output->definition, 'snapshot_archived', null, ['snapshot_id' => $snapshot->id]);
        $this->lifecycle('EVT-RPT-017', $company, $output->request, $output->definition, $request);

        return $snapshot;
    }

    public function history(Company $company, Request $request): Collection
    {
        return ReportRequest::where('company_id', $company->id)->where('user_id', $request->user()?->id)->with(['definition', 'outputs'])->latest()->limit(min(100, max(1, (int) $request->integer('limit', 25))))->get();
    }

    public function favorites(Company $company, Request $request): Collection
    {
        return ReportFavorite::where('company_id', $company->id)->where('user_id', $request->user()?->id)->with(['definition.category'])->orderBy('display_order')->get();
    }

    public function favorite(Company $company, Request $request, array $input): ReportFavorite
    {
        $definition = $this->resolveDefinition((string) $input['definition_key'], isset($input['definition_version']) ? (int) $input['definition_version'] : null);
        $this->assertAccess($definition, $request);
        $favorite = ReportFavorite::updateOrCreate(['company_id' => $company->id, 'user_id' => $request->user()?->id, 'report_definition_id' => $definition->id], ['id' => (string) Str::uuid(), 'label' => $input['label'] ?? $definition->name, 'saved_parameters' => $input['parameters'] ?? [], 'display_order' => (int) ($input['display_order'] ?? 0)]);
        $this->auditAccess($company, $request, null, null, $definition, 'favorited', null, ['favorite_id' => $favorite->id]);
        $this->lifecycle('EVT-RPT-010', $company, null, $definition, $request);

        return $favorite->load('definition');
    }

    public function removeFavorite(Company $company, Request $request, string $id): void
    {
        $favorite = ReportFavorite::where('company_id', $company->id)->where('user_id', $request->user()?->id)->whereKey($id)->with('definition')->firstOrFail();
        $definition = $favorite->definition;
        $favorite->delete();
        $this->auditAccess($company, $request, null, null, $definition, 'favorite_removed', null, ['favorite_id' => $id]);
        $this->lifecycle('EVT-RPT-011', $company, null, $definition, $request);
    }

    public function savedViews(Company $company, Request $request): Collection
    {
        return ReportSavedView::where('company_id', $company->id)->where('user_id', $request->user()?->id)->with('definition')->latest()->get();
    }

    public function saveView(Company $company, Request $request, array $input, ?string $id = null): ReportSavedView
    {
        $definition = $this->resolveDefinition((string) $input['definition_key'], isset($input['definition_version']) ? (int) $input['definition_version'] : null);
        $this->assertAccess($definition, $request);
        $query = ReportSavedView::where('company_id', $company->id)->where('user_id', $request->user()?->id)->where('report_definition_id', $definition->id);
        if ($id) {
            $query->whereKey($id);
        }
        $view = $query->first();
        if ($view) {
            $view->update(['name' => $input['name'], 'parameters' => $input['parameters'] ?? [], 'presentation' => $input['presentation'] ?? []]);
        } else {
            $view = ReportSavedView::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'user_id' => $request->user()?->id, 'report_definition_id' => $definition->id, 'name' => $input['name'], 'parameters' => $input['parameters'] ?? [], 'presentation' => $input['presentation'] ?? []]);
        }

        return $view->load('definition');
    }

    public function deleteView(Company $company, Request $request, string $id): void
    {
        ReportSavedView::where('company_id', $company->id)->where('user_id', $request->user()?->id)->whereKey($id)->firstOrFail()->delete();
    }

    public function publishedDefinition(string $key, ?int $version = null): ReportDefinition
    {
        return $this->resolveDefinition($key, $version);
    }

    public function canUseDefinition(ReportDefinition $definition, $user, int $companyId): bool
    {
        return $this->canAccess($definition, $user, $companyId);
    }

    public function normalizeParameters(ReportDefinition $definition, array $input, Company $company): array
    {
        return $this->validateParameters($definition, $input, $company);
    }

    private function resolveDefinition(string $key, ?int $version = null): ReportDefinition
    {
        $query = ReportDefinition::with(['category', 'parameters', 'columns'])->where('definition_key', $key)->where('status', 'published');
        if ($version) {
            $query->where('version', $version);
        } else {
            $query->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', now());
            })->latest('version');
        }
        $definition = $query->first();
        if (! $definition) {
            throw new RegistryConflictException('The requested report definition is not available.');
        }

        return $definition;
    }

    private function assertAccess(ReportDefinition $definition, Request $request): void
    {
        if (! $this->canAccess($definition, $request->user(), $request->attributes->get('company')?->id)) {
            throw new RegistryConflictException('You are not authorized to use this report or its source data.', ['permission' => data_get($definition->security_schema, 'source_permission')]);
        }
    }

    private function canAccess(ReportDefinition $definition, $user, ?int $companyId = null): bool
    {
        $permission = data_get($definition->security_schema, 'source_permission');
        if (! $user || ! $user->hasPermission('reports.view', $companyId) || ($permission && ! $user->hasPermission($permission, $companyId))) {
            return false;
        }
        $contractPermissions = ReportSourceContract::whereIn('contract_key', $definition->source_contract_keys ?? [])->pluck('source_permission')->filter()->unique();

        return $contractPermissions->every(fn (string $sourcePermission) => $user->hasPermission($sourcePermission, $companyId));
    }

    private function validateParameters(ReportDefinition $definition, array $input, Company $company): array
    {
        $allowed = collect($definition->parameter_schema ?? [])->keyBy('name');
        $unknown = array_diff(array_keys($input), $allowed->keys()->all());
        if ($unknown) {
            throw new RegistryConflictException('The report parameters are not supported by this definition.', ['parameters' => array_values($unknown)]);
        }
        $parameters = [];
        foreach ($allowed as $name => $definitionParameter) {
            $value = array_key_exists($name, $input) ? $input[$name] : ($definitionParameter['default'] ?? null);
            if ($value === null || $value === '') {
                if (($definitionParameter['required'] ?? false) === true) {
                    throw new RegistryConflictException('A required report parameter is missing.', [$name => 'This parameter is required.']);
                }

                continue;
            }
            if (($definitionParameter['type'] ?? '') === 'date') {
                try {
                    $value = Carbon::parse((string) $value, $company->timezone)->toDateString();
                } catch (\Throwable) {
                    throw new RegistryConflictException('A report date parameter is invalid.', [$name => 'Use a valid date.']);
                }
            }
            if (($definitionParameter['type'] ?? '') === 'integer') {
                if (! is_numeric($value)) {
                    throw new RegistryConflictException('A report integer parameter is invalid.', [$name => 'Use a whole number.']);
                } $value = (int) $value;
            }
            $parameters[$name] = $value;
        }
        if (! empty($parameters['from']) && ! empty($parameters['to']) && $parameters['from'] > $parameters['to']) {
            throw new RegistryConflictException('The report period is invalid.', ['to' => 'The end date must be on or after the start date.']);
        }
        $parameters['page'] = max(1, min(100000, (int) ($parameters['page'] ?? 1)));
        $parameters['per_page'] = max(1, min(100, (int) ($parameters['per_page'] ?? 25)));

        return $parameters;
    }

    private function context(Company $company, Request $request, array $parameters): array
    {
        return ['company_id' => $company->id, 'company_name' => $company->name, 'user_id' => $request->user()?->id, 'timezone' => $company->timezone, 'locale' => $company->locale, 'currency' => $company->currency, 'as_of_date' => $parameters['as_of'] ?? $parameters['to'] ?? now($company->timezone)->toDateString(), 'resolved_at' => now($company->timezone)->toISOString()];
    }

    private function sourceRows(ReportDefinition $definition, Company $company, Request $request, array $parameters): array
    {
        $sourceRequest = clone $request;
        $sourceRequest->merge($parameters);
        $raw = match ($definition->query_adapter) {
            'collections.receipt_register' => $this->collections->report('receipt_register', $company, $sourceRequest),
            'collections.payment_method_summary' => $this->collections->report('payment_method_summary', $company, $sourceRequest),
            'sales.sales_register' => $this->sales->report('sales_register', $company, $sourceRequest),
            'sales.sales_by_product' => $this->sales->report('sales_by_product', $company, $sourceRequest),
            'sales.sales_returns_adjustments' => $this->sales->report('sales_returns_adjustments', $company, $sourceRequest),
            'sales.receivables_aging' => $this->sales->report('receivables_aging', $company, $sourceRequest),
            'purchases.purchase-register' => $this->purchases->reports('purchase-register', $company, $sourceRequest),
            'purchases.payables-aging' => $this->purchases->reports('payables-aging', $company, $sourceRequest),
            'payments.payment-register' => $this->payments->report($company, $sourceRequest),
            'inventory.inventory_position' => $this->inventory->report('inventory_position', $company, $sourceRequest),
            'inventory.stock_card' => $this->inventory->report('stock_card', $company, $sourceRequest),
            'inventory.valuation' => $this->inventory->report('valuation', $company, $sourceRequest),
            'expenses.expense_register' => $this->expenses->listHistory($company, $sourceRequest),
            'expenses.by-category' => $this->expenseCompletion->report('by-category', $company, $sourceRequest),
            'cash-accounts.cash_position' => $this->cashPositions->report('cash_position', $company, $sourceRequest),
            'cash-accounts.cash_account_ledger' => $this->cashPositions->report('cash_account_ledger', $company, $sourceRequest),
            'cash-accounts.cash_movement_history' => $this->cashPositions->report('cash_movement_history', $company, $sourceRequest),
            'cash-accounts.cash_transfer_history' => $this->cashPositions->report('cash_transfer_history', $company, $sourceRequest),
            'cash-accounts.cash_count' => $this->cashPositions->report('cash_count', $company, $sourceRequest),
            'cash-accounts.cash_reconciliation' => $this->cashPositions->report('cash_reconciliation', $company, $sourceRequest),
            'cash-accounts.cash_exceptions' => $this->cashPositions->report('cash_exceptions', $company, $sourceRequest),
            'reports.business_performance' => $this->businessPerformance($company, $sourceRequest),
            'reports.voided_reversed' => $this->voidedAndReversed($company, $sourceRequest),
            'reports.export_history' => ['rows' => ReportAccessAudit::where('company_id', $company->id)->with('definition')->latest()->limit(500)->get(), 'source_as_of_at' => now(), 'freshness_state' => 'current'],
            default => throw new RegistryConflictException('The report source contract is not available.'),
        };
        $materialized = $this->materialize($raw);
        $rows = $this->extractRows($materialized);

        return ['rows' => $rows, 'source_as_of_at' => now(), 'freshness_state' => 'current', 'currency_context' => data_get($materialized, 'currency_context', 'Amounts remain separated by source currency.')];
    }

    private function businessPerformance(Company $company, Request $request): array
    {
        $from = $request->input('from');
        $to = $request->input('to') ?: now($company->timezone)->toDateString();
        $sales = $this->sales->report('sales_register', $company, $request)['rows'];
        $metrics = [];
        foreach (collect($sales)->groupBy('currency') as $currency => $rows) {
            $metrics[] = ['id' => 'sales-'.$currency, 'metric' => 'Net Sales', 'currency' => $currency, 'value' => (string) collect($rows)->sum(fn ($row) => (float) ($row['amount'] ?? 0)), 'comparison_value' => null, 'change' => null, 'source_status' => 'available'];
        }

        $expenses = Expense::where('company_id', $company->id)->whereIn('status', ['approved', 'payment_ready', 'scheduled', 'partially_paid', 'paid', 'closed'])->when($from, fn ($query) => $query->whereDate('business_date', '>=', $from))->whereDate('business_date', '<=', $to)->with('currency')->get();
        foreach ($expenses->groupBy(fn (Expense $expense) => $expense->currency?->code ?: 'UNKNOWN') as $currency => $rows) {
            $metrics[] = ['id' => 'expenses-'.$currency, 'metric' => 'Expenses', 'currency' => $currency, 'value' => (string) $rows->sum(fn (Expense $expense) => (float) $expense->total), 'comparison_value' => null, 'change' => null, 'source_status' => 'available'];
        }

        foreach ($this->cashPositions->report('cash_position', $company, (clone $request)->merge(['as_of' => $to]))['rows'] as $row) {
            $metrics[] = ['id' => 'cash-'.$row['id'], 'metric' => 'Cash Position', 'currency' => $row['currency'], 'value' => $row['posted'], 'comparison_value' => null, 'change' => null, 'source_status' => 'available', 'account' => $row['account']];
        }
        foreach (ReceivableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->with('currency')->get()->groupBy(fn (ReceivableOpenItem $item) => $item->currency?->code ?: 'UNKNOWN') as $currency => $rows) {
            $metrics[] = ['id' => 'receivables-'.$currency, 'metric' => 'Open Receivables', 'currency' => $currency, 'value' => (string) $rows->sum(fn (ReceivableOpenItem $item) => (float) $item->remaining_amount), 'comparison_value' => null, 'change' => null, 'source_status' => 'available'];
        }
        foreach (PayableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->with('currency')->get()->groupBy(fn (PayableOpenItem $item) => $item->currency?->code ?: 'UNKNOWN') as $currency => $rows) {
            $metrics[] = ['id' => 'payables-'.$currency, 'metric' => 'Open Payables', 'currency' => $currency, 'value' => (string) $rows->sum(fn (PayableOpenItem $item) => (float) $item->remaining_amount), 'comparison_value' => null, 'change' => null, 'source_status' => 'available'];
        }
        $inventory = InventoryBalance::where('company_id', $company->id)->where('status', 'active')->get();
        $metrics[] = ['id' => 'inventory-quantity', 'metric' => 'Available Inventory Quantity', 'currency' => null, 'value' => (string) $inventory->sum(fn (InventoryBalance $balance) => (float) $balance->available), 'comparison_value' => null, 'change' => null, 'source_status' => 'available', 'unit_basis' => 'source quantity across enabled inventory balances'];

        return ['rows' => $metrics, 'source_as_of_at' => now(), 'freshness_state' => 'current', 'currency_context' => 'Management metrics remain separated by source currency. Inventory quantity is not a currency amount.', 'analytics_definition' => 'ANL-MGT-001'];
    }

    private function voidedAndReversed(Company $company, Request $request): array
    {
        $from = $request->input('from');
        $to = $request->input('to');
        $rows = collect();
        Sale::where('company_id', $company->id)->whereIn('status', ['cancelled', 'reversed'])->when($from, fn ($query) => $query->whereDate('sale_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('sale_date', '<=', $to))->get()->each(fn (Sale $sale) => $rows->push(['id' => $sale->id, 'module' => 'sales', 'record_number' => $sale->sale_number, 'date' => $sale->sale_date?->toDateString(), 'status' => $sale->status, 'reason' => $sale->cancellation_reason ?: $sale->reversal_reason, 'actor' => $sale->reversed_by ?: $sale->cancelled_by]));
        $rows = $rows->sortByDesc('date')->values()->all();

        return ['rows' => $rows, 'source_as_of_at' => now(), 'freshness_state' => 'current', 'currency_context' => 'Control records retain source status and reason without changing source workflows.'];
    }

    private function extractRows(mixed $value): array
    {
        if (is_array($value) && array_key_exists('rows', $value)) {
            return array_values((array) $value['rows']);
        }
        if (is_array($value) && array_key_exists('data', $value) && is_array($value['data'])) {
            return array_values($value['data']);
        }
        if (is_array($value) && array_key_exists('items', $value) && is_array($value['items'])) {
            return array_values($value['items']);
        }
        if (is_array($value) && array_key_exists(0, $value) && array_key_exists(1, $value) && is_array($value[1]) && array_key_exists('total', $value[1])) {
            return array_values((array) $value[0]);
        }

        return is_array($value) ? array_values($value) : [];
    }

    private function materialize(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $value->toArray();
        }
        if ($value instanceof Collection) {
            return $value->map(fn ($item) => $this->materialize($item))->values()->all();
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }
        if (is_array($value)) {
            return array_map(fn ($item) => $this->materialize($item), $value);
        }

        return $value;
    }

    private function sortRows(array $rows, array $parameters, ReportDefinition $definition): array
    {
        $allowed = collect($definition->columns_schema ?? [])->pluck('key')->all();
        $sort = $parameters['sort'] ?? data_get($definition->sorting_schema, 'default');
        if (! $sort || ! in_array($sort, $allowed, true)) {
            return array_values($rows);
        }
        $direction = strtolower((string) ($parameters['direction'] ?? 'desc')) === 'asc' ? 1 : -1;
        usort($rows, function ($left, $right) use ($sort, $direction) {
            $a = data_get($left, $sort);
            $b = data_get($right, $sort);
            if ($a === $b) {
                return 0;
            }

            return (($a <=> $b) ?: strcmp((string) $a, (string) $b)) * $direction;
        });

        return $rows;
    }

    private function totals(array $rows, ReportDefinition $definition): array
    {
        $totalColumns = data_get($definition->totals_schema, 'allowed', []);
        $totals = ['row_count' => count($rows)];
        foreach ($totalColumns as $column) {
            $byCurrency = [];
            foreach ($rows as $row) {
                $value = data_get($row, $column);
                if (! is_numeric($value)) {
                    continue;
                } $currency = (string) (data_get($row, 'currency.code') ?? data_get($row, 'currency') ?? data_get($row, 'currency_code') ?? 'source');
                $byCurrency[$currency] = (string) ((float) ($byCurrency[$currency] ?? 0) + (float) $value);
            }
            $totals[$column] = ['by_currency' => $byCurrency, 'currency_separated' => true];
        }

        return $totals;
    }

    private function definitionSummary(ReportDefinition $definition, bool $favorite = false, bool $recent = false): array
    {
        return ['id' => $definition->id, 'definition_key' => $definition->definition_key, 'version' => $definition->version, 'code' => $definition->code, 'name' => $definition->name, 'description' => $definition->description, 'business_question' => $definition->business_question, 'category' => $definition->category?->only(['code', 'name']), 'source_owner_module' => $definition->source_owner_module, 'sensitivity' => $definition->sensitivity, 'status_basis' => $definition->status_basis, 'output_capabilities' => $definition->output_capabilities, 'favorite' => $favorite, 'recent' => $recent, 'source_contract_keys' => $definition->source_contract_keys];
    }

    private function auditAccess(Company $company, Request $request, ?ReportRequest $reportRequest, ?ReportOutput $output, ?ReportDefinition $definition, string $action, ?string $format, array $metadata): void
    {
        if (! $definition) {
            return;
        }
        ReportAccessAudit::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'user_id' => $request->user()?->id, 'report_request_id' => $reportRequest?->id, 'report_output_id' => $output?->id, 'report_definition_id' => $definition->id, 'definition_version' => $definition->version, 'action' => $action, 'format' => $format, 'parameter_hash' => $reportRequest ? hash('sha256', json_encode($this->safeParameters($reportRequest->parameters ?? []))) : null, 'source_modules' => [$definition->source_owner_module], 'result_state' => $metadata['result_state'] ?? $reportRequest?->status, 'metadata' => $metadata, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now()]);
    }

    private function require(Request $request, Company $company, string $permission): void
    {
        if (! $request->user()?->hasPermission($permission, $company->id)) {
            throw new RegistryConflictException('You are not authorized for this reporting action.', ['permission' => $permission]);
        }
    }

    private function lifecycle(string $eventCode, Company $company, ?ReportRequest $reportRequest, ?ReportDefinition $definition, Request $request): void
    {
        event(new ReportLifecycleEvent($eventCode, $company->id, $reportRequest?->id, $definition?->definition_key, $definition?->version, $request->user()?->id, $request->attributes->get('correlation_id')));
    }

    private function safeParameters(array $parameters): array
    {
        unset($parameters['token'], $parameters['password'], $parameters['secret']);

        return $parameters;
    }
}
