<?php

namespace App\Services;

use App\Events\ReportLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\Company;
use App\Models\ReportAnalyticsDefinition;
use App\Models\ReportDefinition;
use App\Models\ReportDelivery;
use App\Models\ReportOutput;
use App\Models\ReportPack;
use App\Models\ReportPackItem;
use App\Models\ReportRequest;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleOccurrence;
use App\Models\ReportSourceContract;
use App\Models\User;
use App\Support\AuditService;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ReportCompletionService
{
    public function __construct(private readonly ReportsService $reports, private readonly AuditService $audit) {}

    public function schedules(Company $company, Request $request): Collection
    {
        $query = ReportSchedule::where('company_id', $company->id)->with(['definition.category', 'owner'])->latest();
        if (! $request->user()?->hasPermission('reports.schedules.manage', $company->id)) {
            $query->where('owner_id', $request->user()?->id);
        }

        return $query->get();
    }

    public function createSchedule(Company $company, Request $request, array $input): ReportSchedule
    {
        $this->require($request, $company, 'reports.schedules.manage');
        $definition = $this->reports->publishedDefinition((string) $input['definition_key'], isset($input['definition_version']) ? (int) $input['definition_version'] : null);
        $this->assertUsable($definition, $request->user(), $company);
        $parameters = $this->reports->normalizeParameters($definition, (array) ($input['parameters'] ?? []), $company);
        $recurrence = (string) ($input['recurrence'] ?? 'daily');
        if (! in_array($recurrence, ['daily', 'weekly', 'monthly'], true)) {
            throw new RegistryConflictException('Only daily, weekly, and monthly report recurrence is supported.');
        }
        $timezone = (string) ($input['timezone'] ?? $company->timezone ?: config('app.timezone'));
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new RegistryConflictException('The schedule timezone is invalid.');
        }
        $runTime = (string) ($input['run_time'] ?? '08:00');
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $runTime)) {
            throw new RegistryConflictException('The schedule run time must use HH:MM format.');
        }
        if ($recurrence === 'weekly' && ! isset($input['day_of_week'])) {
            throw new RegistryConflictException('A weekday is required for a weekly schedule.');
        }
        if ($recurrence === 'monthly' && ! isset($input['day_of_month'])) {
            throw new RegistryConflictException('A day of month is required for a monthly schedule.');
        }
        $startsAt = Carbon::parse($input['starts_at'] ?? now($timezone)->toDateString(), $timezone);
        $endsAt = ! empty($input['ends_at']) ? Carbon::parse($input['ends_at'], $timezone) : null;
        if ($endsAt && $endsAt->lt($startsAt)) {
            throw new RegistryConflictException('The schedule end must be on or after its start.');
        }
        $recipients = $this->recipients($company, $definition, $request->user(), (array) ($input['recipient_user_ids'] ?? [$request->user()?->id]));
        $next = $this->nextOccurrence($startsAt, $recurrence, $runTime, isset($input['day_of_week']) ? (int) $input['day_of_week'] : null, isset($input['day_of_month']) ? (int) $input['day_of_month'] : null);
        if ($endsAt && $next->gt($endsAt)) {
            throw new RegistryConflictException('The schedule has no occurrence within its configured date range.');
        }
        $schedule = ReportSchedule::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'owner_id' => $request->user()?->id, 'report_definition_id' => $definition->id, 'definition_version' => $definition->version, 'name' => trim((string) $input['name']), 'recurrence' => $recurrence, 'timezone' => $timezone, 'run_time' => $runTime, 'day_of_week' => $input['day_of_week'] ?? null, 'day_of_month' => $input['day_of_month'] ?? null, 'parameters' => $parameters, 'output_format' => $input['output_format'] ?? 'pdf', 'delivery_channel' => 'in_app', 'recipient_user_ids' => $recipients->pluck('id')->values()->all(), 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'next_run_at' => $next, 'status' => ($input['status'] ?? 'active') === 'draft' ? 'draft' : 'active', 'version' => 1, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $this->audit($request, 'reports.schedule.created', $schedule, [], $this->safeSchedule($schedule), 'Report schedule created.');
        event(new ReportLifecycleEvent('EVT-RPT-012', $company->id, null, $definition->definition_key, $definition->version, $request->user()?->id, $request->attributes->get('correlation_id')));

        return $schedule->load(['definition.category', 'owner']);
    }

    public function updateSchedule(Company $company, Request $request, string $id, array $input): ReportSchedule
    {
        $this->require($request, $company, 'reports.schedules.manage');
        $schedule = ReportSchedule::where('company_id', $company->id)->whereKey($id)->with('definition')->firstOrFail();
        if (isset($input['version']) && (int) $input['version'] !== (int) $schedule->version) {
            throw new RegistryConflictException('This report schedule changed. Refresh and try again.', ['version_conflict' => true]);
        }
        if (in_array($schedule->status, ['cancelled', 'expired'], true)) {
            throw new RegistryConflictException('A cancelled or expired report schedule cannot be edited.');
        }
        $definition = $this->reports->publishedDefinition($schedule->definition->definition_key, $schedule->definition_version);
        $this->assertUsable($definition, $request->user(), $company);
        $parameters = array_key_exists('parameters', $input) ? $this->reports->normalizeParameters($definition, (array) $input['parameters'], $company) : $schedule->parameters;
        $updates = array_intersect_key($input, array_flip(['name', 'recurrence', 'timezone', 'run_time', 'day_of_week', 'day_of_month', 'output_format', 'ends_at']));
        $updates['parameters'] = $parameters;
        if (array_key_exists('recipient_user_ids', $input)) {
            $updates['recipient_user_ids'] = $this->recipients($company, $definition, $request->user(), (array) $input['recipient_user_ids'])->pluck('id')->values()->all();
        }
        $updates['version'] = $schedule->version + 1;
        $updates['updated_by'] = $request->user()?->id;
        $schedule->update($updates);
        if ($schedule->status === 'active') {
            $schedule->update(['next_run_at' => $this->nextOccurrence(now($schedule->timezone), $schedule->recurrence, $schedule->run_time, $schedule->day_of_week, $schedule->day_of_month)]);
        }
        $this->audit($request, 'reports.schedule.changed', $schedule, [], $this->safeSchedule($schedule), 'Report schedule changed.');
        event(new ReportLifecycleEvent('EVT-RPT-013', $company->id, null, $definition->definition_key, $definition->version, $request->user()?->id, $request->attributes->get('correlation_id')));

        return $schedule->fresh(['definition.category', 'owner']);
    }

    public function changeSchedule(Company $company, Request $request, string $id, string $action): ReportSchedule
    {
        $this->require($request, $company, 'reports.schedules.manage');
        $schedule = ReportSchedule::where('company_id', $company->id)->whereKey($id)->with('definition')->firstOrFail();
        if ($action === 'activate' || $action === 'resume') {
            $definition = $this->reports->publishedDefinition($schedule->definition->definition_key, null);
            $this->assertUsable($definition, $request->user(), $company);
            $schedule->update(['status' => 'active', 'failure_code' => null, 'failure_message' => null, 'paused_at' => null, 'next_run_at' => $this->nextOccurrence(now($schedule->timezone), $schedule->recurrence, $schedule->run_time, $schedule->day_of_week, $schedule->day_of_month), 'version' => $schedule->version + 1, 'updated_by' => $request->user()?->id]);
        } elseif ($action === 'pause') {
            if ($schedule->status !== 'active') {
                throw new RegistryConflictException('Only an active report schedule can be paused.');
            }
            $schedule->update(['status' => 'paused', 'paused_at' => now(), 'version' => $schedule->version + 1, 'updated_by' => $request->user()?->id]);
        } elseif ($action === 'cancel') {
            if (in_array($schedule->status, ['cancelled', 'expired'], true)) {
                throw new RegistryConflictException('This report schedule has already stopped.');
            }
            $schedule->update(['status' => 'cancelled', 'cancelled_at' => now(), 'next_run_at' => null, 'version' => $schedule->version + 1, 'updated_by' => $request->user()?->id]);
        } else {
            throw new RegistryConflictException('The requested report schedule action is not supported.');
        }
        $this->audit($request, 'reports.schedule.'.strtolower($action), $schedule, [], $this->safeSchedule($schedule), 'Report schedule lifecycle changed.');

        return $schedule->fresh(['definition.category', 'owner']);
    }

    public function occurrences(Company $company, Request $request, string $id): Collection
    {
        $schedule = ReportSchedule::where('company_id', $company->id)->whereKey($id)->firstOrFail();
        if (! $request->user()?->hasPermission('reports.schedules.manage', $company->id) && $schedule->owner_id !== $request->user()?->id) {
            throw new RegistryConflictException('You are not authorized to view this schedule history.');
        }

        return $schedule->occurrences()->with(['output', 'request'])->latest('scheduled_for')->limit(100)->get();
    }

    public function deliveries(Company $company, Request $request): Collection
    {
        $query = ReportDelivery::where('company_id', $company->id)->with(['recipient', 'output.definition'])->latest();
        if (! $request->user()?->hasPermission('reports.delivery.view', $company->id)) {
            $query->where('recipient_user_id', $request->user()?->id);
        }

        return $query->limit(100)->get();
    }

    public function retryDelivery(Company $company, Request $request, string $id): ReportDelivery
    {
        $this->require($request, $company, 'reports.delivery.retry');
        $delivery = ReportDelivery::where('company_id', $company->id)->whereKey($id)->with(['recipient', 'output.definition'])->firstOrFail();
        if (! in_array($delivery->status, ['failed', 'partially_sent'], true)) {
            throw new RegistryConflictException('Only failed report deliveries can be retried.');
        }
        if ($delivery->expires_at && $delivery->expires_at->isPast()) {
            $delivery->update(['status' => 'expired', 'failure_code' => 'DELIVERY_EXPIRED']);

            return $delivery->refresh();
        }
        if (! $this->reports->canUseDefinition($delivery->output->definition, $delivery->recipient, $company->id)) {
            throw new RegistryConflictException('The recipient no longer has permission to receive this report.', ['recipient' => $delivery->recipient_user_id]);
        }
        $delivery->update(['status' => 'sent', 'attempts' => $delivery->attempts + 1, 'last_attempt_at' => now(), 'delivered_at' => now(), 'failure_code' => null, 'failure_message' => null]);
        $this->audit($request, 'reports.delivery.retried', $delivery, [], ['delivery_id' => $delivery->id, 'status' => $delivery->status], 'Report delivery retry completed through the in-app channel.');

        return $delivery->fresh(['recipient', 'output.definition']);
    }

    public function runDue(?Company $onlyCompany = null): int
    {
        $query = ReportSchedule::where('status', 'active')->whereNotNull('next_run_at')->where('next_run_at', '<=', now());
        if ($onlyCompany) {
            $query->where('company_id', $onlyCompany->id);
        }
        $count = 0;
        foreach ($query->orderBy('next_run_at')->get() as $schedule) {
            $this->execute($schedule);
            $count++;
        }

        return $count;
    }

    public function runSchedule(Company $company, Request $request, string $id): ReportSchedule
    {
        $this->require($request, $company, 'reports.schedules.run');
        $schedule = ReportSchedule::where('company_id', $company->id)->whereKey($id)->with('definition')->firstOrFail();
        if (in_array($schedule->status, ['cancelled', 'expired'], true)) {
            throw new RegistryConflictException('A cancelled or expired report schedule cannot be run.');
        }
        $schedule->update(['status' => 'active', 'next_run_at' => now($schedule->timezone), 'failure_code' => null, 'failure_message' => null, 'version' => $schedule->version + 1]);
        $this->execute($schedule->fresh(['definition']));

        return $schedule->fresh(['definition.category', 'owner']);
    }

    public function completeQueuedRequest(ReportRequest $reportRequest): void
    {
        $occurrence = ReportScheduleOccurrence::where('report_request_id', $reportRequest->id)->first();
        if (! $occurrence && ($occurrenceId = data_get($reportRequest->context, 'schedule_occurrence_id'))) {
            $occurrence = ReportScheduleOccurrence::whereKey($occurrenceId)->first();
        }
        if (! $occurrence || $occurrence->status === 'completed') {
            return;
        }
        $schedule = ReportSchedule::whereKey($occurrence->schedule_id)->with('definition')->first();
        $company = $schedule ? Company::find($schedule->company_id) : null;
        $owner = $schedule ? User::find($schedule->owner_id) : null;
        $output = $reportRequest->outputs()->where('status', 'available')->first();
        if (! $schedule || ! $company || ! $owner || ! $output) {
            $this->failQueuedRequest($reportRequest, 'SCHEDULED_REPORT_OUTPUT_MISSING', 'The scheduled report completed without an available output.');

            return;
        }
        try {
            $definition = $this->reports->publishedDefinition($schedule->definition->definition_key, $schedule->definition_version);
            $recipients = $this->recipients($company, $definition, $owner, (array) $schedule->recipient_user_ids);
        } catch (\Throwable $exception) {
            $this->failQueuedRequest($reportRequest, 'SCHEDULED_REPORT_AUTHORIZATION_INVALID', Str::limit($exception->getMessage(), 500));

            return;
        }
        $scheduledFor = Carbon::parse($occurrence->scheduled_for);
        $sent = 0;
        foreach ($recipients as $recipient) {
            $delivery = ReportDelivery::firstOrCreate(['occurrence_id' => $occurrence->id, 'recipient_user_id' => $recipient->id, 'channel' => 'in_app'], ['id' => (string) Str::uuid(), 'company_id' => $company->id, 'schedule_id' => $schedule->id, 'report_output_id' => $output->id, 'status' => 'pending', 'expires_at' => now()->addDays((int) config('reports.retention_days', 30)), 'correlation_id' => $occurrence->correlation_id]);
            if ($this->reports->canUseDefinition($definition, $recipient, $company->id)) {
                if ($delivery->status !== 'sent') {
                    $delivery->update(['status' => 'sent', 'attempts' => $delivery->attempts + 1, 'last_attempt_at' => now(), 'delivered_at' => now(), 'failure_code' => null, 'failure_message' => null]);
                }
                $sent++;
            } else {
                $delivery->update(['status' => 'failed', 'failure_code' => 'RECIPIENT_AUTHORIZATION_INVALID', 'failure_message' => 'Recipient authorization was revoked before delivery.']);
            }
        }
        $occurrence->update(['status' => 'completed', 'report_request_id' => $reportRequest->id, 'report_output_id' => $output->id, 'completed_at' => now(), 'failure_code' => null, 'failure_message' => null]);
        $schedule->update(['last_run_at' => now(), 'failure_code' => null, 'failure_message' => null]);
        $this->advance($schedule, $scheduledFor);
        event(new ReportLifecycleEvent('EVT-RPT-014', $company->id, $reportRequest->id, $definition->definition_key, $definition->version, $owner->id, $occurrence->correlation_id));
        event(new ReportLifecycleEvent($sent === count($recipients) ? 'EVT-RPT-015' : 'EVT-RPT-016', $company->id, $reportRequest->id, $definition->definition_key, $definition->version, $owner->id, $occurrence->correlation_id));
    }

    public function failQueuedRequest(ReportRequest $reportRequest, string $code = 'SCHEDULED_REPORT_FAILED', string $message = 'The scheduled report could not be completed.'): void
    {
        $occurrence = ReportScheduleOccurrence::where('report_request_id', $reportRequest->id)->first();
        if (! $occurrence && ($occurrenceId = data_get($reportRequest->context, 'schedule_occurrence_id'))) {
            $occurrence = ReportScheduleOccurrence::whereKey($occurrenceId)->first();
        }
        if (! $occurrence || $occurrence->status === 'completed') {
            return;
        }
        $occurrence->update(['status' => 'failed', 'report_request_id' => $reportRequest->id, 'failed_at' => now(), 'failure_code' => $code, 'failure_message' => Str::limit($message, 500)]);
    }

    public function compare(Company $company, Request $request, array $input): array
    {
        $definition = $this->reports->publishedDefinition((string) $input['definition_key'], isset($input['definition_version']) ? (int) $input['definition_version'] : null);
        $this->assertUsable($definition, $request->user(), $company);
        $primary = (array) ($input['primary_parameters'] ?? []);
        $comparison = (array) ($input['comparison_parameters'] ?? []);
        $first = $this->generateForComparison($company, $request, $definition, $primary, 'primary');
        $second = $this->generateForComparison($company, $request, $definition, $comparison, 'comparison');

        return ['definition_key' => $definition->definition_key, 'definition_version' => $definition->version, 'comparability' => ['same_definition_version' => true, 'currency_context' => 'Comparison values remain separated by source currency.'], 'primary' => $first->toArray(), 'comparison' => $second->toArray()];
    }

    public function analytics(): Collection
    {
        return ReportAnalyticsDefinition::where('status', 'published')->orderBy('name')->get();
    }

    public function governance(Request $request): Collection
    {
        return ReportDefinition::with('category')->orderBy('definition_key')->orderByDesc('version')->get();
    }

    public function supersedeDefinition(Company $company, Request $request, string $key, array $input): ReportDefinition
    {
        $this->require($request, $company, 'reports.definitions.manage');
        $source = ReportDefinition::where('definition_key', $key)->whereKey($input['source_definition_id'] ?? null)->first();
        if (! $source) {
            $source = ReportDefinition::where('definition_key', $key)->orderByDesc('version')->firstOrFail();
        }
        $newVersion = (int) ReportDefinition::where('definition_key', $key)->max('version') + 1;
        $definition = DB::transaction(function () use ($source, $newVersion, $input) {
            $definition = $source->replicate();
            $definition->id = (string) Str::uuid();
            $definition->version = $newVersion;
            $definition->name = $input['name'] ?? $source->name;
            $definition->description = $input['description'] ?? $source->description;
            $definition->business_question = $input['business_question'] ?? $source->business_question;
            $definition->status = 'draft';
            $definition->review_status = 'draft';
            $definition->reviewed_by = null;
            $definition->reviewed_at = null;
            $definition->published_at = null;
            $definition->effective_from = null;
            $definition->effective_to = null;
            $definition->supersedes_id = $source->id;
            $definition->save();
            foreach ($source->parameters as $parameter) {
                $clone = $parameter->replicate();
                $clone->id = (string) Str::uuid();
                $clone->report_definition_id = $definition->id;
                $clone->save();
            }
            foreach ($source->columns as $column) {
                $clone = $column->replicate();
                $clone->id = (string) Str::uuid();
                $clone->report_definition_id = $definition->id;
                $clone->save();
            }

            return $definition;
        });
        $this->audit($request, 'reports.definition.superseded', $definition, [], ['definition_key' => $key, 'version' => $newVersion], 'A draft report definition version was created from a prior version.');

        return $definition->load(['category', 'parameters', 'columns']);
    }

    public function publishDefinition(Company $company, Request $request, string $id, array $input): ReportDefinition
    {
        $this->require($request, $company, 'reports.definitions.publish');
        $definition = ReportDefinition::whereKey($id)->with(['parameters', 'columns'])->firstOrFail();
        $this->validateDefinitionForPublication($definition);
        ReportDefinition::where('definition_key', $definition->definition_key)->where('id', '!=', $definition->id)->where('status', 'published')->update(['status' => 'superseded', 'effective_to' => now(), 'updated_at' => now()]);
        $definition->update(['status' => 'published', 'review_status' => 'approved', 'reviewed_by' => $request->user()?->id, 'reviewed_at' => now(), 'published_at' => now(), 'effective_from' => now(), 'review_notes' => $input['review_notes'] ?? null]);
        $this->audit($request, 'reports.definition.published', $definition, [], ['definition_key' => $definition->definition_key, 'version' => $definition->version], 'A tested report definition version was published.');
        event(new ReportLifecycleEvent('EVT-RPT-018', $company->id, null, $definition->definition_key, $definition->version, $request->user()?->id, $request->attributes->get('correlation_id')));

        return $definition->fresh(['category', 'parameters', 'columns']);
    }

    public function deactivateDefinition(Company $company, Request $request, string $id, string $reason): ReportDefinition
    {
        $this->require($request, $company, 'reports.definitions.publish');
        $definition = ReportDefinition::whereKey($id)->firstOrFail();
        if ($reason === '') {
            throw new RegistryConflictException('A reason is required to deactivate a report definition.');
        }
        $definition->update(['status' => 'inactive', 'review_status' => 'approved', 'deactivated_by' => $request->user()?->id, 'deactivated_at' => now(), 'effective_to' => now(), 'review_notes' => $reason]);
        $this->audit($request, 'reports.definition.deactivated', $definition, [], ['definition_key' => $definition->definition_key, 'version' => $definition->version, 'reason' => $reason], 'Report definition deactivated.');

        return $definition->fresh(['category']);
    }

    public function packs(Company $company, Request $request): Collection
    {
        $this->require($request, $company, 'reports.packs.view');

        return ReportPack::where('company_id', $company->id)->with(['items.output.definition'])->latest()->get();
    }

    public function createPack(Company $company, Request $request, array $input): ReportPack
    {
        $this->require($request, $company, 'reports.packs.manage');
        $packKey = $input['pack_key'] ?? Str::slug((string) $input['name']).'-'.Str::lower(Str::random(6));
        $pack = ReportPack::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'pack_key' => $packKey, 'version' => 1, 'name' => trim((string) $input['name']), 'description' => $input['description'] ?? null, 'status' => 'draft', 'parameters' => $input['parameters'] ?? [], 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $this->audit($request, 'reports.pack.created', $pack, [], ['pack_key' => $pack->pack_key, 'version' => $pack->version], 'Report Pack draft created.');

        return $pack->load('items');
    }

    public function addPackItem(Company $company, Request $request, string $id, array $input): ReportPack
    {
        $this->require($request, $company, 'reports.packs.manage');
        $pack = ReportPack::where('company_id', $company->id)->whereKey($id)->firstOrFail();
        if ($pack->status !== 'draft') {
            throw new RegistryConflictException('Published or generated Report Packs are immutable. Create a superseding Pack to revise them.');
        }
        $output = ReportOutput::where('company_id', $company->id)->whereKey($input['report_output_id'] ?? null)->with('definition')->firstOrFail();
        if ($output->status !== 'available' || $output->purged_at) {
            throw new RegistryConflictException('Only available, retained report outputs can be added to a Report Pack.');
        }
        ReportPackItem::firstOrCreate(['report_pack_id' => $pack->id, 'report_output_id' => $output->id], ['id' => (string) Str::uuid(), 'display_order' => (int) ($input['display_order'] ?? $pack->items()->count()), 'label' => $input['label'] ?? $output->definition->name, 'definition_key' => $output->definition->definition_key, 'definition_version' => $output->definition_version]);

        return $pack->fresh('items.output.definition');
    }

    public function transitionPack(Company $company, Request $request, string $id, string $action): ReportPack
    {
        $permission = in_array($action, ['publish', 'supersede'], true) ? 'reports.packs.publish' : 'reports.packs.manage';
        $this->require($request, $company, $permission);
        $pack = ReportPack::where('company_id', $company->id)->whereKey($id)->with('items.output')->firstOrFail();
        if ($action === 'generate') {
            if ($pack->status !== 'draft' || $pack->items->isEmpty() || $pack->items->contains(fn (ReportPackItem $item) => $item->output?->status !== 'available')) {
                throw new RegistryConflictException('A Report Pack must contain available outputs before generation.');
            }
            $pack->update(['status' => 'generated', 'generated_at' => now(), 'metadata' => ['output_count' => $pack->items->count(), 'definition_versions' => $pack->items->map(fn ($item) => [$item->definition_key, $item->definition_version])->values()->all()]]);
        } elseif ($action === 'review') {
            if (! in_array($pack->status, ['generated', 'reviewed'], true)) {
                throw new RegistryConflictException('Only generated Report Packs can be reviewed.');
            }
            $pack->update(['status' => 'reviewed', 'reviewed_by' => $request->user()?->id, 'reviewed_at' => now()]);
        } elseif ($action === 'publish') {
            if (! in_array($pack->status, ['generated', 'reviewed'], true)) {
                throw new RegistryConflictException('Only generated or reviewed Report Packs can be published.');
            }
            $pack->update(['status' => 'published', 'published_by' => $request->user()?->id, 'published_at' => now()]);
        } elseif ($action === 'archive') {
            if ($pack->status === 'published') {
                $pack->update(['status' => 'archived', 'archived_at' => now()]);
            } else {
                throw new RegistryConflictException('Only a published Report Pack can be archived.');
            }
        } else {
            throw new RegistryConflictException('The requested Report Pack action is not supported.');
        }
        $this->audit($request, 'reports.pack.'.strtolower($action), $pack, [], ['pack_id' => $pack->id, 'status' => $pack->status], 'Report Pack lifecycle changed.');

        return $pack->fresh('items.output.definition');
    }

    public function purgeExpired(Company $company, Request $request): array
    {
        $this->require($request, $company, 'reports.retention.manage');
        $outputs = ReportOutput::where('company_id', $company->id)->whereNull('purged_at')->where('legal_hold', false)->whereNotNull('retention_expires_at')->where('retention_expires_at', '<', now())->get();
        foreach ($outputs as $output) {
            if ($output->storage_path) {
                Storage::disk($output->storage_disk ?: 'local')->delete($output->storage_path);
            }
            $output->update(['status' => 'purged', 'result_data' => null, 'purged_at' => now(), 'purge_reason' => 'retention_expired']);
            $this->audit($request, 'reports.output.purged', $output, [], ['output_id' => $output->id], 'Report output purged after retention expiry.');
        }

        return ['purged' => $outputs->count(), 'durability' => 'Local Render disk is ephemeral; this action does not claim durable archive storage.'];
    }

    private function execute(ReportSchedule $schedule): void
    {
        $company = Company::find($schedule->company_id);
        $owner = User::find($schedule->owner_id);
        if (! $company || ! $owner) {
            $schedule->update(['status' => 'failed', 'failure_code' => 'SCHEDULE_OWNER_INVALID', 'failure_message' => 'The schedule owner is no longer available.']);

            return;
        }
        try {
            $definition = $this->reports->publishedDefinition($schedule->definition->definition_key, $schedule->definition_version);
            $this->assertUsable($definition, $owner, $company);
            $recipients = $this->recipients($company, $definition, $owner, (array) $schedule->recipient_user_ids);
        } catch (\Throwable $exception) {
            $schedule->update(['status' => 'failed', 'failure_code' => 'SCHEDULE_AUTHORIZATION_INVALID', 'failure_message' => Str::limit($exception->getMessage(), 500)]);

            return;
        }
        $scheduledFor = Carbon::parse($schedule->next_run_at);
        if ($schedule->ends_at && $scheduledFor->gt($schedule->ends_at)) {
            $schedule->update(['status' => 'expired', 'expired_at' => now(), 'next_run_at' => null]);

            return;
        }
        $occurrenceKey = $scheduledFor->utc()->toIso8601String();
        $occurrence = ReportScheduleOccurrence::firstOrCreate(['schedule_id' => $schedule->id, 'occurrence_key' => $occurrenceKey], ['id' => (string) Str::uuid(), 'scheduled_for' => $scheduledFor, 'definition_version' => $schedule->definition_version, 'parameters' => $schedule->parameters, 'status' => 'pending', 'correlation_id' => $schedule->correlation_id ?: (string) Str::uuid()]);
        if ($occurrence->status === 'completed') {
            $this->advance($schedule, $scheduledFor);

            return;
        }
        $occurrence->update(['status' => 'processing', 'started_at' => now(), 'attempts' => $occurrence->attempts + 1]);
        $request = Request::create('/api/v1/reports/requests', 'POST');
        $request->setUserResolver(fn () => $owner);
        $request->attributes->set('company', $company);
        $request->attributes->set('correlation_id', $occurrence->correlation_id);
        $request->headers->set('Idempotency-Key', 'schedule:'.$schedule->id.':'.$occurrenceKey);
        try {
            $reportRequest = $this->reports->generate($company, $request, ['definition_key' => $definition->definition_key, 'definition_version' => $definition->version, 'parameters' => $schedule->parameters, 'output_type' => $schedule->output_format, '_force_async' => true, '_context' => ['schedule_occurrence_id' => $occurrence->id]]);
            $occurrence->refresh();
            if ($occurrence->status !== 'completed' && $occurrence->status !== 'failed') {
                $occurrence->update(['status' => $reportRequest->status === 'completed' ? 'processing' : 'queued', 'report_request_id' => $reportRequest->id]);
                if ($reportRequest->status === 'completed') {
                    $this->completeQueuedRequest($reportRequest);
                }
            }
        } catch (\Throwable $exception) {
            $occurrence->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => 'SCHEDULED_REPORT_FAILED', 'failure_message' => Str::limit($exception->getMessage(), 500)]);
            $schedule->update(['failure_code' => 'SCHEDULED_REPORT_FAILED', 'failure_message' => Str::limit($exception->getMessage(), 500)]);
            event(new ReportLifecycleEvent('EVT-RPT-016', $company->id, null, $definition->definition_key, $definition->version, $owner->id, $occurrence->correlation_id));
        }
    }

    private function generateForComparison(Company $company, Request $request, ReportDefinition $definition, array $parameters, string $identity)
    {
        $comparisonRequest = Request::create('/api/v1/reports/requests', 'POST');
        $comparisonRequest->setUserResolver(fn () => $request->user());
        $comparisonRequest->attributes->set('company', $company);
        $comparisonRequest->attributes->set('correlation_id', $request->attributes->get('correlation_id'));
        $comparisonRequest->headers->set('Idempotency-Key', 'comparison:'.$identity.':'.Str::uuid());

        return $this->reports->generate($company, $comparisonRequest, ['definition_key' => $definition->definition_key, 'definition_version' => $definition->version, 'parameters' => $parameters, 'output_type' => 'display']);
    }

    private function recipients(Company $company, ReportDefinition $definition, ?User $owner, array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            throw new RegistryConflictException('At least one report recipient is required.');
        }
        $recipients = $company->users()->whereIn('users.id', $ids)->where('users.status', 'active')->wherePivot('status', 'active')->get();
        if ($recipients->count() !== count($ids)) {
            throw new RegistryConflictException('Every report recipient must be an active user in the current company.');
        }
        foreach ($recipients as $recipient) {
            $this->assertUsable($definition, $recipient, $company);
        }

        return $recipients;
    }

    private function assertUsable(ReportDefinition $definition, ?User $user, Company $company): void
    {
        if ($definition->status !== 'published' || ! $this->reports->canUseDefinition($definition, $user, $company->id)) {
            throw new RegistryConflictException('The report definition, source permission, or entitlement is not currently available.');
        }
    }

    private function validateDefinitionForPublication(ReportDefinition $definition): void
    {
        if (! $definition->source_contract_keys || ! $definition->parameters->count() || ! $definition->columns->count()) {
            throw new RegistryConflictException('A report definition requires source contracts, parameters, and columns before publication.');
        }
        $contracts = ReportSourceContract::whereIn('contract_key', $definition->source_contract_keys)->where('status', 'published')->count();
        if ($contracts !== count($definition->source_contract_keys)) {
            throw new RegistryConflictException('Every report source contract must be published before definition publication.');
        }
    }

    private function advance(ReportSchedule $schedule, Carbon $scheduledFor): void
    {
        $next = $this->nextOccurrence($scheduledFor->copy()->addMinute(), $schedule->recurrence, $schedule->run_time, $schedule->day_of_week, $schedule->day_of_month);
        if ($schedule->ends_at && $next->gt($schedule->ends_at)) {
            $schedule->update(['status' => 'expired', 'expired_at' => now(), 'next_run_at' => null]);
        } else {
            $schedule->update(['next_run_at' => $next, 'version' => $schedule->version + 1]);
        }
    }

    private function nextOccurrence(Carbon $seed, string $recurrence, string $runTime, ?int $dayOfWeek, ?int $dayOfMonth): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $runTime));
        $candidate = $seed->copy()->setTime($hour, $minute, 0);
        if ($recurrence === 'daily') {
            return $candidate->lt($seed) ? $candidate->addDay() : $candidate;
        }
        if ($recurrence === 'weekly') {
            $candidate->startOfDay();
            while ((int) $candidate->dayOfWeek !== (int) $dayOfWeek || $candidate->lt($seed->copy()->startOfDay())) {
                $candidate->addDay();
            }

            return $candidate->setTime($hour, $minute, 0);
        }
        $candidate->day(min(max((int) $dayOfMonth, 1), $candidate->daysInMonth));
        if ($candidate->lt($seed)) {
            $candidate->addMonthNoOverflow()->day(min(max((int) $dayOfMonth, 1), $candidate->daysInMonth));
        }

        return $candidate;
    }

    private function require(Request $request, Company $company, string $permission): void
    {
        if (! $request->user()?->hasPermission($permission, $company->id)) {
            throw new RegistryConflictException('You are not authorized for this reporting action.', ['permission' => $permission]);
        }
    }

    private function audit(Request $request, string $action, $record, array $before, array $after, string $description): void
    {
        $this->audit->record($request, $action, $record, $record->company_id ?? $request->attributes->get('company')?->id, $before, $after, null, 'Reports & Analytics', $description);
    }

    private function safeSchedule(ReportSchedule $schedule): array
    {
        return ['id' => $schedule->id, 'name' => $schedule->name, 'definition_version' => $schedule->definition_version, 'recurrence' => $schedule->recurrence, 'status' => $schedule->status, 'next_run_at' => $schedule->next_run_at?->toIso8601String(), 'recipient_count' => count((array) $schedule->recipient_user_ids)];
    }
}
