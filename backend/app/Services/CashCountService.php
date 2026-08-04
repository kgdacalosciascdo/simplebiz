<?php

namespace App\Services;

use App\Events\CashMovementLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashAccountCustodian;
use App\Models\CashAdjustment;
use App\Models\CashCount;
use App\Models\CashCountAttempt;
use App\Models\CashCountConfirmation;
use App\Models\CashCountDenominationLine;
use App\Models\CashCountNonDenominationLine;
use App\Models\CashCountStatusHistory;
use App\Models\CashCountType;
use App\Models\CashCountVariance;
use App\Models\CashDenomination;
use App\Models\CashMovement;
use App\Models\CashMovementDocument;
use App\Models\CashMovementPurpose;
use App\Models\Company;
use App\Models\ReasonCode;
use App\Models\User;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class CashCountService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers, private readonly CashMovementService $movements) {}

    public function create(array $input, Company $company, Request $request): CashCount
    {
        $account = $this->account($input['cash_account_id'] ?? null, $company);
        $type = CashCountType::where('code', $input['count_type'] ?? 'ROUTINE')->where('status', 'active')->first();
        if (! $type) {
            throw new RegistryConflictException('The selected Cash Count type is not active.');
        }
        $this->eligible($account, $company);
        $this->userInCompany($input['counter_id'] ?? $request->user()?->id, $company);
        if (! empty($input['witness_id'])) {
            $this->userInCompany($input['witness_id'], $company);
        }
        if (! empty($input['incoming_custodian_id'])) {
            $this->userInCompany($input['incoming_custodian_id'], $company);
        }
        $this->reason($input['reason_code_id'] ?? null, 'CASH_COUNT', $company);
        $cutoff = CarbonImmutable::parse($input['cut_off_at'] ?? now());
        if ($cutoff->isFuture()) {
            throw new RegistryConflictException('The Cash Count cut-off cannot be in the future.');
        }
        if ((string) $account->currency_id !== (string) ($input['currency_id'] ?? $account->currency_id)) {
            throw new RegistryConflictException('The Cash Count currency must match the Cash Account currency.');
        }
        if (! empty($input['branch_id']) && ! Branch::where('company_id', $company->id)->whereKey($input['branch_id'])->where('status', 'active')->exists()) {
            throw new RegistryConflictException('The Cash Count branch must be active and belong to the current company.');
        }
        $document = CashCount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'count_number' => DB::transaction(fn () => $this->numbers->next($company->id, 'cash_count')), 'cash_account_id' => $account->id, 'count_type_id' => $type->id, 'currency_id' => $account->currency_id, 'branch_id' => $input['branch_id'] ?? $account->branch_id, 'status' => 'scheduled', 'count_date' => $input['count_date'] ?? $cutoff->toDateString(), 'cut_off_at' => $cutoff, 'post_cutoff_policy' => $input['post_cutoff_policy'] ?? 'detect_and_review', 'count_reason' => $input['count_reason'] ?? $type->code, 'scheduled' => (bool) ($input['scheduled'] ?? false), 'current_custodian_id' => $this->currentCustodian($account)?->user_id, 'counter_id' => $input['counter_id'] ?? $request->user()?->id, 'witness_id' => $input['witness_id'] ?? null, 'incoming_custodian_id' => $input['incoming_custodian_id'] ?? null, 'outgoing_custodian_id' => $input['outgoing_custodian_id'] ?? $this->currentCustodian($account)?->user_id, 'reason_code_id' => $input['reason_code_id'], 'explanation' => $input['explanation'], 'version' => 1, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
        $this->history($document, null, 'scheduled', $company, $request);
        $this->audit->record($request, 'cash-count.scheduled', $document, $company->id, [], $this->safe($document), null, 'Cash Count scheduled', 'A governed Cash Count was created without changing the Cash Account balance.');

        return $this->load($document);
    }

    public function start(CashCount $count, Company $company, Request $request): CashCount
    {
        $this->scope($count, $company);
        $result = DB::transaction(function () use ($count, $company, $request) {
            $locked = CashCount::whereKey($count->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'scheduled') {
                throw new RegistryConflictException('Only scheduled Cash Counts may be started.');
            }
            $account = $this->account($locked->cash_account_id, $company);
            $this->eligible($account, $company);
            $snapshot = $this->snapshot($account, CarbonImmutable::parse($locked->cut_off_at));
            $attempt = CashCountAttempt::create(['id' => (string) Str::uuid(), 'cash_count_id' => $locked->id, 'attempt_number' => 1, 'counted_by' => $locked->counter_id, 'started_at' => now(), 'status' => 'draft', 'is_current' => true, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $locked->update(['status' => 'in_progress', 'expected_amount' => $snapshot['amount'], 'expected_as_of_at' => $locked->cut_off_at, 'expected_snapshot_generated_at' => now(), 'expected_snapshot_hash' => $snapshot['hash'], 'expected_movement_count' => $snapshot['count'], 'post_cutoff_movement_count' => $snapshot['post_cutoff_count'], 'post_cutoff_movement_ids' => $snapshot['post_cutoff_ids'], 'count_started_at' => now(), 'started_by' => $request->user()?->id, 'current_attempt_id' => $attempt->id, 'version' => $locked->version + 1]);
            $this->history($locked, 'scheduled', 'in_progress', $company, $request);

            return $locked->refresh();
        });
        $this->audit->record($request, 'cash-count.started', $result, $company->id, [], $this->safe($result), null, 'Cash Count started', 'Expected cash was snapshotted from posted movements at the count cut-off.');
        Event::dispatch(new CashMovementLifecycleEvent('cash-count.started', $company->id, CashCount::class, $result->id));

        return $this->load($result);
    }

    public function createAttempt(CashCount $count, array $input, Company $company, Request $request): CashCountAttempt
    {
        $this->scope($count, $company);
        if (! in_array($count->status, ['in_progress', 'variance_review'], true)) {
            throw new RegistryConflictException('A new attempt may only be created while a Cash Count is in progress or under variance review.');
        }
        if (($count->recount_count ?? 0) > 0 && trim((string) ($input['recount_reason'] ?? '')) === '') {
            throw new RegistryConflictException('A recount reason is required.');
        }
        $attempt = DB::transaction(function () use ($count, $input, $company, $request) {
            $locked = CashCount::whereKey($count->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            CashCountAttempt::where('cash_count_id', $locked->id)->where('is_current', true)->update(['is_current' => false]);
            $number = ((int) CashCountAttempt::where('cash_count_id', $locked->id)->max('attempt_number')) + 1;
            $attempt = CashCountAttempt::create(['id' => (string) Str::uuid(), 'cash_count_id' => $locked->id, 'attempt_number' => $number, 'counted_by' => $locked->counter_id, 'started_at' => now(), 'status' => 'draft', 'recount_reason' => $input['recount_reason'] ?? null, 'is_current' => true, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $locked->update(['status' => 'in_progress', 'current_attempt_id' => $attempt->id, 'recount_count' => max(0, $number - 1), 'recount_required' => false, 'version' => $locked->version + 1]);

            return $attempt;
        });
        $this->audit->record($request, 'cash-count.attempt.created', $count, $company->id, [], ['attempt_id' => $attempt->id, 'attempt_number' => $attempt->attempt_number], null, 'Cash Count attempt created', 'A new count attempt was created while prior evidence was preserved.');

        return $attempt->load(['denominations.denomination', 'nonDenominations']);
    }

    public function saveAttempt(CashCountAttempt $attempt, array $input, Company $company, Request $request): CashCountAttempt
    {
        $attempt->load('count.type');
        $count = $attempt->count;
        $this->scope($count, $company);
        if ($attempt->status !== 'draft' || ! $attempt->is_current) {
            throw new RegistryConflictException('Only the current draft attempt may be edited.');
        }
        if ((int) ($input['version'] ?? 0) !== (int) $attempt->version) {
            throw new RegistryConflictException('This count attempt changed. Refresh before saving.', ['version_conflict' => true]);
        }
        $denominationInput = $input['denominations'] ?? [];
        $ids = array_map(fn ($line) => (string) ($line['denomination_id'] ?? ''), $denominationInput);
        if (count($ids) !== count(array_unique($ids))) {
            throw new RegistryConflictException('A denomination may appear only once in an attempt.');
        }
        $denominations = CashDenomination::whereIn('id', $ids)->where('currency_id', $count->currency_id)->where('status', 'active')->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $company->id))->get()->keyBy('id');
        if ($denominations->count() !== count(array_filter($ids))) {
            throw new RegistryConflictException('Every denomination must be active, same-currency, and available to this company.');
        }
        $allowNonDenom = (bool) ($count->type->rules['allow_non_denomination'] ?? false);
        if (! $allowNonDenom && ! empty($input['non_denomination_lines'])) {
            throw new RegistryConflictException('This Cash Count type does not permit non-denomination lines.');
        }
        $saved = DB::transaction(function () use ($attempt, $denominationInput, $denominations, $input) {
            CashCountDenominationLine::where('attempt_id', $attempt->id)->delete();
            CashCountNonDenominationLine::where('attempt_id', $attempt->id)->delete();
            $total = 0.0;
            foreach ($denominationInput as $line) {
                $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($quantity === false) {
                    throw new RegistryConflictException('Denomination quantity must be a nonnegative whole number.');
                }
                $denomination = $denominations->get((string) $line['denomination_id']);
                $amount = (float) $denomination->face_value * $quantity;
                $total += $amount;
                CashCountDenominationLine::create(['id' => (string) Str::uuid(), 'attempt_id' => $attempt->id, 'denomination_id' => $denomination->id, 'face_value_snapshot' => $denomination->face_value, 'label_snapshot' => $denomination->display_label, 'quantity' => $quantity, 'line_amount' => $this->money($amount), 'notes' => $line['notes'] ?? null]);
            }
            foreach ($input['non_denomination_lines'] ?? [] as $line) {
                if (! is_numeric($line['amount'] ?? null) || (float) $line['amount'] <= 0) {
                    throw new RegistryConflictException('Non-denomination amounts must be greater than zero.');
                }
                $total += (float) $line['amount'];
                CashCountNonDenominationLine::create(['id' => (string) Str::uuid(), 'attempt_id' => $attempt->id, 'item_type' => $line['item_type'], 'description' => $line['description'], 'amount' => $line['amount'], 'notes' => $line['notes'] ?? null]);
            }
            $attempt->update(['actual_amount' => $this->money($total), 'version' => $attempt->version + 1]);

            return $attempt->refresh();
        });
        $this->audit->record($request, 'cash-count.attempt.saved', $count, $company->id, [], ['attempt_id' => $saved->id, 'actual_amount' => (string) $saved->actual_amount], null, 'Cash Count attempt saved', 'Denomination values and quantities were calculated server-side.');

        return $saved->load(['denominations.denomination', 'nonDenominations']);
    }

    public function confirm(CashCountAttempt $attempt, string $type, string $comments, Company $company, Request $request): CashCountConfirmation
    {
        $attempt->load('count.type');
        $count = $attempt->count;
        $this->scope($count, $company);
        if ($attempt->status !== 'draft' || ! $attempt->is_current) {
            throw new RegistryConflictException('Only the current draft attempt may be confirmed.');
        }
        if (! in_array($type, ['witness', 'custodian'], true)) {
            throw new RegistryConflictException('Unsupported Cash Count confirmation type.');
        }
        if ($type === 'custodian' && (int) $count->current_custodian_id !== (int) $request->user()?->id && (int) $count->outgoing_custodian_id !== (int) $request->user()?->id) {
            throw new RegistryConflictException('Only the current or outgoing custodian may confirm custody.');
        }
        if ($type === 'witness' && (int) $count->counter_id === (int) $request->user()?->id) {
            throw new RegistryConflictException('The counter cannot witness the same attempt.');
        }

        return CashCountConfirmation::updateOrCreate(['attempt_id' => $attempt->id, 'confirmation_type' => $type], ['id' => (string) Str::uuid(), 'cash_count_id' => $count->id, 'user_id' => $request->user()?->id, 'status' => 'confirmed', 'comments' => $comments, 'confirmed_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    public function submitAttempt(CashCountAttempt $attempt, Company $company, Request $request): CashCount
    {
        $attempt->load(['count.type', 'denominations', 'nonDenominations']);
        $count = $attempt->count;
        $this->scope($count, $company);
        if ($attempt->status !== 'draft' || ! $attempt->is_current || $count->status !== 'in_progress') {
            throw new RegistryConflictException('Only the current in-progress attempt may be submitted.');
        }
        $rules = $count->type->rules ?? [];
        if (($rules['evidence_required'] ?? false) && ! $count->attachments()->exists()) {
            throw new RegistryConflictException('Count-sheet evidence is required before submission.', ['dependency' => 'evidence']);
        }
        if (($rules['witness_required'] ?? false) && ! $attempt->confirmations()->where('confirmation_type', 'witness')->where('status', 'confirmed')->exists()) {
            throw new RegistryConflictException('Witness confirmation is required before submission.', ['dependency' => 'witness_confirmation']);
        }
        if (($rules['custodian_confirmation_required'] ?? false) && ! $attempt->confirmations()->where('confirmation_type', 'custodian')->where('status', 'confirmed')->exists()) {
            throw new RegistryConflictException('Custodian confirmation is required before submission.', ['dependency' => 'custodian_confirmation']);
        }
        $actual = (float) $attempt->denominations->sum('line_amount') + (float) $attempt->nonDenominations->sum('amount');
        $expected = (float) $count->expected_amount;
        $variance = $actual - $expected;
        $tolerance = (float) ($rules['tolerance_amount'] ?? 0);
        $classification = abs($variance) < 0.0000005 ? 'balanced' : ($variance > 0 ? 'overage' : 'shortage');
        $result = DB::transaction(function () use ($attempt, $count, $company, $request, $actual, $variance, $tolerance, $classification) {
            $attempt->update(['status' => 'submitted', 'completed_at' => now(), 'actual_amount' => $this->money($actual), 'version' => $attempt->version + 1]);
            $varianceRecord = CashCountVariance::firstOrNew(['cash_count_id' => $count->id]);
            $varianceRecord->fill(['company_id' => $company->id, 'accepted_attempt_id' => $attempt->id, 'expected_amount' => $count->expected_amount, 'actual_amount' => $this->money($actual), 'variance_amount' => $this->money($variance), 'classification' => $classification, 'tolerance_amount' => $this->money($tolerance), 'within_tolerance' => abs($variance) <= $tolerance, 'status' => $classification === 'balanced' ? 'balanced' : 'disposition_pending', 'owner_id' => $count->counter_id, 'version' => $varianceRecord->exists ? $varianceRecord->version + 1 : 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $varianceRecord->save();
            $count->update(['status' => 'submitted', 'actual_amount' => $this->money($actual), 'variance_amount' => $this->money($variance), 'variance_classification' => $classification, 'variance_id' => $varianceRecord->id, 'submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'version' => $count->version + 1]);
            $this->history($count, 'in_progress', 'submitted', $company, $request);

            return $count->refresh();
        });
        $this->audit->record($request, 'cash-count.submitted', $result, $company->id, [], $this->safe($result), null, 'Cash Count submitted', 'The server calculated the actual amount and variance from the submitted attempt.');

        return $this->load($result);
    }

    public function review(CashCount $count, Company $company, Request $request): CashCount
    {
        $this->scope($count, $company);
        if ($count->status !== 'submitted') {
            throw new RegistryConflictException('Only submitted Cash Counts may enter variance review.');
        }
        if ((int) $count->counter_id === (int) $request->user()?->id) {
            throw new RegistryConflictException('The counter cannot review the same Cash Count.', ['segregation' => true]);
        }
        $this->transition($count, 'variance_review', $company, $request, ['reviewer_id' => $request->user()?->id, 'reviewed_at' => now(), 'version' => $count->version + 1]);

        return $this->load($count->refresh());
    }

    public function approve(CashCount $count, Company $company, Request $request): CashCount
    {
        $this->scope($count, $company);
        if ($count->status !== 'variance_review') {
            throw new RegistryConflictException('Only Cash Counts in variance review may be approved.');
        }
        if ((int) $count->counter_id === (int) $request->user()?->id || (int) $count->reviewer_id === (int) $request->user()?->id) {
            throw new RegistryConflictException('The preparer or reviewer cannot approve the same Cash Count.', ['segregation' => true]);
        }
        $variance = $count->variance;
        if ($variance && ! in_array($variance->status, ['balanced', 'approved', 'waived'], true)) {
            throw new RegistryConflictException('A variance disposition is required before approval.');
        }
        $this->transition($count, 'approved', $company, $request, ['approver_id' => $request->user()?->id, 'approved_at' => now(), 'version' => $count->version + 1]);

        return $this->load($count->refresh());
    }

    public function disposition(CashCount $count, array $input, Company $company, Request $request): CashCount
    {
        $this->scope($count, $company);
        if ($count->status !== 'variance_review') {
            throw new RegistryConflictException('Only Cash Counts in variance review may receive a disposition.');
        }
        if ((int) $count->counter_id === (int) $request->user()?->id) {
            throw new RegistryConflictException('The counter cannot disposition the same variance.', ['segregation' => true]);
        }
        $variance = $count->variance()->firstOrFail();
        $disposition = strtoupper((string) ($input['disposition'] ?? ''));
        if (! in_array($disposition, ['ADJUST_CASH', 'WAIVE_WITH_APPROVAL', 'INVESTIGATE', 'RECOUNT'], true)) {
            throw new RegistryConflictException('The variance disposition is not supported.');
        }
        if ($disposition === 'RECOUNT') {
            return $this->requestRecount($count, $input['reason'] ?? '', $company, $request);
        }
        if ($disposition === 'ADJUST_CASH') {
            $this->reason($input['reason_code_id'] ?? null, 'CASH_MOVEMENT', $company);
            if (! AccountTitle::where('company_id', $company->id)->whereKey($input['offset_account_title_id'] ?? null)->where('status', 'active')->exists()) {
                throw new RegistryConflictException('An active same-company adjustment offset Account Title is required.');
            }
            $adjustment = CashAdjustment::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'adjustment_number' => DB::transaction(fn () => $this->numbers->next($company->id, 'cash_adjustment')), 'cash_count_id' => $count->id, 'variance_id' => $variance->id, 'status' => 'prepared', 'direction' => (float) $variance->variance_amount > 0 ? 'increase' : 'decrease', 'amount' => abs((float) $variance->variance_amount), 'offset_account_title_id' => $input['offset_account_title_id'], 'reason_code_id' => $input['reason_code_id'], 'reason' => $input['reason'], 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $purpose = CashMovementPurpose::where('code', $adjustment->direction === 'increase' ? 'DIRECT_CASH_IN' : 'DIRECT_CASH_OUT')->firstOrFail();
            $movementDocument = CashMovementDocument::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_number' => DB::transaction(fn () => $this->numbers->next($company->id, $adjustment->direction === 'increase' ? 'cash_in' : 'cash_out')), 'purpose_id' => $purpose->id, 'source_type' => 'CASH_ADJUSTMENT', 'source_record_type' => CashAdjustment::class, 'source_record_id' => $adjustment->id, 'external_reference' => $adjustment->adjustment_number, 'cash_account_id' => $count->cash_account_id, 'currency_id' => $count->currency_id, 'direction' => $adjustment->direction, 'amount' => $adjustment->amount, 'business_date' => $count->count_date, 'offset_account_title_id' => $adjustment->offset_account_title_id, 'reason_code_id' => $adjustment->reason_code_id, 'explanation' => 'Cash Count variance adjustment: '.$input['reason'], 'status' => 'draft', 'version' => 1, 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $adjustment->update(['cash_movement_document_id' => $movementDocument->id]);
            $variance->update(['status' => 'approved', 'disposition' => $disposition, 'disposition_reason' => $input['reason'], 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'adjustment_id' => $adjustment->id, 'version' => $variance->version + 1]);
            $count->update(['adjustment_id' => $adjustment->id, 'version' => $count->version + 1]);
            $this->audit->record($request, 'cash-count.variance.dispositioned', $count, $company->id, [], ['disposition' => $disposition, 'adjustment_id' => $adjustment->id], $input['reason'], 'Cash variance dispositioned', 'An approved variance adjustment was prepared without changing the Cash Account balance.');
        } else {
            $variance->update(['status' => $disposition === 'WAIVE_WITH_APPROVAL' ? 'waived' : 'investigating', 'disposition' => $disposition, 'disposition_reason' => $input['reason'], 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $variance->version + 1]);
            if ($disposition === 'WAIVE_WITH_APPROVAL') {
                $this->transition($count, 'approved', $company, $request, ['approver_id' => $request->user()?->id, 'approved_at' => now(), 'version' => $count->version + 1]);
            }
        }

        return $this->load($count->refresh());
    }

    public function requestRecount(CashCount $count, string $reason, Company $company, Request $request): CashCount
    {
        if (trim($reason) === '') {
            throw new RegistryConflictException('A reason is required for a recount.');
        }
        $count->load('currentAttempt');
        $this->scope($count, $company);
        if (! in_array($count->status, ['submitted', 'variance_review'], true)) {
            throw new RegistryConflictException('Only a submitted or reviewed count may be recounted.');
        }
        $this->createAttempt($count, ['recount_reason' => $reason], $company, $request);

        return $this->load($count->refresh());
    }

    public function approveAdjustment(CashAdjustment $adjustment, Company $company, Request $request): CashAdjustment
    {
        $this->scopeAdjustment($adjustment, $company);
        if ($adjustment->status !== 'prepared') {
            throw new RegistryConflictException('Only prepared Cash Count adjustments may be approved.');
        }
        if ((int) $adjustment->prepared_by === (int) $request->user()?->id) {
            throw new RegistryConflictException('The adjustment preparer cannot approve the same adjustment.', ['segregation' => true]);
        }
        $document = CashMovementDocument::whereKey($adjustment->cash_movement_document_id)->where('company_id', $company->id)->firstOrFail();
        $adjustment->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $adjustment->version + 1]);
        $document->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $document->version + 1]);
        $this->audit->record($request, 'cash-adjustment.approved', $adjustment, $company->id, [], $this->safe($adjustment), null, 'Cash adjustment approved', 'The count-linked adjustment is ready for governed posting.');

        return $adjustment->refresh()->load(['count', 'variance', 'movementDocument']);
    }

    public function postAdjustment(CashAdjustment $adjustment, Company $company, Request $request): CashAdjustment
    {
        $this->scopeAdjustment($adjustment, $company);
        if ($adjustment->status !== 'approved') {
            throw new RegistryConflictException('Only approved Cash Count adjustments may be posted.');
        }
        $document = CashMovementDocument::whereKey($adjustment->cash_movement_document_id)->where('company_id', $company->id)->with('purpose')->firstOrFail();
        $posted = $this->movements->post($document, $company, $request);
        $adjustment->update(['status' => 'posted', 'cash_movement_id' => $posted->cash_movement_id, 'accounting_transaction_id' => $posted->accounting_transaction_id, 'posted_by' => $request->user()?->id, 'posted_at' => now(), 'version' => $adjustment->version + 1]);
        $adjustment->variance()->update(['status' => 'adjustment_posted', 'version' => $adjustment->variance->version + 1]);
        $adjustment->count()->update(['status' => 'adjusted', 'version' => $adjustment->count->version + 1]);
        $this->audit->record($request, 'cash-adjustment.posted', $adjustment, $company->id, [], $this->safe($adjustment), null, 'Cash adjustment posted', 'The approved count variance created one linked Cash Movement and balanced accounting effect.');
        Event::dispatch(new CashMovementLifecycleEvent('cash-adjustment.posted', $company->id, CashAdjustment::class, $adjustment->id));

        return $adjustment->refresh()->load(['count', 'variance', 'movementDocument', 'movement']);
    }

    public function reverseAdjustment(CashAdjustment $adjustment, string $reason, Company $company, Request $request): CashAdjustment
    {
        $this->scopeAdjustment($adjustment, $company);
        if ($adjustment->status !== 'posted' || ! $adjustment->cash_movement_document_id) {
            throw new RegistryConflictException('Only a posted count adjustment may be reversed.');
        }
        if (trim($reason) === '') {
            throw new RegistryConflictException('A reason is required for an adjustment reversal.');
        }
        $document = CashMovementDocument::whereKey($adjustment->cash_movement_document_id)->where('company_id', $company->id)->with('purpose')->firstOrFail();
        $reversalDocument = $this->movements->reverse($document, $reason, $company, $request);
        $reversal = CashAdjustment::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'adjustment_number' => DB::transaction(fn () => $this->numbers->next($company->id, 'cash_adjustment')), 'cash_count_id' => $adjustment->cash_count_id, 'variance_id' => $adjustment->variance_id, 'status' => 'posted', 'direction' => $adjustment->direction === 'increase' ? 'decrease' : 'increase', 'amount' => $adjustment->amount, 'cash_movement_document_id' => $reversalDocument->id, 'cash_movement_id' => $reversalDocument->cash_movement_id, 'accounting_transaction_id' => $reversalDocument->accounting_transaction_id, 'original_adjustment_id' => $adjustment->id, 'reason' => $reason, 'posted_by' => $request->user()?->id, 'posted_at' => now(), 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $adjustment->update(['status' => 'reversed', 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'reversal_adjustment_id' => $reversal->id, 'version' => $adjustment->version + 1]);
        $adjustment->variance()->update(['status' => 'disposition_pending', 'adjustment_id' => null, 'version' => $adjustment->variance->version + 1]);
        $adjustment->count()->update(['status' => 'variance_review', 'adjustment_id' => null, 'version' => $adjustment->count->version + 1]);
        $this->audit->record($request, 'cash-adjustment.reversed', $reversal, $company->id, [], $this->safe($reversal), $reason, 'Cash adjustment reversed', 'The count-linked adjustment was reversed through the existing governed Cash Movement reversal path.');

        return $reversal->load(['count', 'variance', 'movementDocument', 'movement']);
    }

    public function close(CashCount $count, Company $company, Request $request): CashCount
    {
        $this->scope($count, $company);
        if (! in_array($count->status, ['approved', 'adjusted'], true)) {
            throw new RegistryConflictException('Only approved or adjusted Cash Counts may be closed.');
        }
        if ((int) $count->post_cutoff_movement_count > 0) {
            throw new RegistryConflictException('Post-cut-off movement activity must be reviewed before closing this count.', ['post_cutoff_movement' => true]);
        }
        if ($count->variance && ! in_array($count->variance->status, ['balanced', 'waived', 'adjustment_posted'], true)) {
            throw new RegistryConflictException('The Cash Count still has an unresolved variance.');
        }
        $this->transition($count, 'closed', $company, $request, ['closed_by' => $request->user()?->id, 'closed_at' => now(), 'count_completed_at' => now(), 'version' => $count->version + 1]);
        CashAccount::whereKey($count->cash_account_id)->update(['last_count_at' => now()]);

        return $this->load($count->refresh());
    }

    public function cancel(CashCount $count, string $reason, Company $company, Request $request): CashCount
    {
        $this->scope($count, $company);
        if (! in_array($count->status, ['scheduled', 'in_progress', 'submitted', 'variance_review'], true)) {
            throw new RegistryConflictException('Only unclosed Cash Counts may be cancelled.');
        }
        if (trim($reason) === '') {
            throw new RegistryConflictException('A reason is required to cancel a Cash Count.');
        }
        $this->transition($count, 'cancelled', $company, $request, ['cancelled_by' => $request->user()?->id, 'cancelled_at' => now(), 'version' => $count->version + 1]);

        return $this->load($count->refresh());
    }

    public function load(CashCount $count): CashCount
    {
        return $count->load(['account.currency', 'account.type', 'account.branch', 'type', 'currency', 'branch', 'currentAttempt.denominations.denomination', 'currentAttempt.nonDenominations', 'currentAttempt.confirmations', 'attempts.denominations', 'variance', 'adjustment.movementDocument', 'adjustment.movement', 'confirmations', 'attachments']);
    }

    private function snapshot(CashAccount $account, CarbonImmutable $cutoff): array
    {
        $movements = CashMovement::where('company_id', $account->company_id)->where('cash_account_id', $account->id)->where('movement_status', 'posted')->whereDate('business_date', '<=', $cutoff->toDateString())->where('posted_at', '<=', $cutoff)->orderBy('posted_at')->orderBy('id')->get(['id', 'direction', 'amount', 'business_date', 'posted_at']);
        $postCutoff = CashMovement::where('company_id', $account->company_id)->where('cash_account_id', $account->id)->where('movement_status', 'posted')->whereDate('business_date', '<=', $cutoff->toDateString())->where('posted_at', '>', $cutoff)->pluck('id')->values();
        $amount = $movements->reduce(fn (float $total, CashMovement $movement) => $total + ($movement->direction === 'increase' ? (float) $movement->amount : -(float) $movement->amount), 0.0);
        $identity = $movements->map(fn ($movement) => $movement->id.':'.$movement->direction.':'.$movement->amount)->implode('|');

        return ['amount' => $this->money($amount), 'count' => $movements->count(), 'hash' => hash('sha256', $account->id.'|'.$cutoff->toIso8601String().'|'.$identity), 'post_cutoff_count' => $postCutoff->count(), 'post_cutoff_ids' => $postCutoff->all()];
    }

    private function account(?string $id, Company $company): CashAccount
    {
        return CashAccount::where('company_id', $company->id)->whereKey($id)->with(['currency', 'type', 'branch'])->firstOrFail();
    }

    private function eligible(CashAccount $account, Company $company): void
    {
        if ($account->status !== 'active') {
            throw new RegistryConflictException('The Cash Account must be active to be counted.');
        }
        if (! $account->type?->supports_cash_count || ! $account->capabilities()->where('capability', 'CASH_COUNT')->where('enabled', true)->exists()) {
            throw new RegistryConflictException('This Cash Account is not eligible for Cash Counts.', ['capability' => 'CASH_COUNT']);
        }
        if ((int) $account->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The Cash Account is outside the current company scope.');
        }
    }

    private function currentCustodian(CashAccount $account): ?CashAccountCustodian
    {
        return $account->custodians()->where('status', 'active')->where('is_primary', true)->whereDate('effective_from', '<=', now()->toDateString())->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))->first();
    }

    private function userInCompany(?int $userId, Company $company): User
    {
        $user = User::whereKey($userId)->where('status', 'active')->whereHas('companies', fn ($query) => $query->whereKey($company->id)->where('company_user.status', 'active'))->first();
        if (! $user) {
            throw new RegistryConflictException('The selected operational user must be active in the current company.');
        }

        return $user;
    }

    private function reason(?string $id, string $domain, Company $company): void
    {
        if (! $id || ! ReasonCode::where('company_id', $company->id)->whereKey($id)->where('domain', $domain)->where('status', 'active')->exists()) {
            throw new RegistryConflictException("An active same-company {$domain} Reason Code is required.", ['dependency' => 'reason_code']);
        }
    }

    private function scope(CashCount $count, Company $company): void
    {
        if ((int) $count->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The Cash Count is outside the current company scope.');
        }
    }

    private function scopeAdjustment(CashAdjustment $adjustment, Company $company): void
    {
        if ((int) $adjustment->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The Cash Adjustment is outside the current company scope.');
        }
    }

    private function transition(CashCount $count, string $to, Company $company, Request $request, array $updates): void
    {
        $from = $count->status;
        $count->update(array_merge($updates, ['status' => $to]));
        $this->history($count, $from, $to, $company, $request, $updates['reason'] ?? null);
        $this->audit->record($request, 'cash-count.'.strtolower($to), $count, $company->id, [], $this->safe($count), $updates['reason'] ?? null, 'Cash Count '.$to, 'Cash Count workflow status changed.');
    }

    private function history(CashCount $count, ?string $from, string $to, Company $company, Request $request, ?string $reason = null): void
    {
        CashCountStatusHistory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_count_id' => $count->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function money(float $amount): string
    {
        return number_format($amount, 6, '.', '');
    }

    private function safe($record): array
    {
        return collect($record->toArray())->except(['expected_snapshot_hash', 'idempotency_identity'])->all();
    }
}
