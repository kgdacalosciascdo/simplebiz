<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Reconciliation;
use App\Models\ReconciliationCompletion;
use App\Models\ReconciliationHistory;
use App\Models\ReconciliationMatch;
use App\Models\ReconciliationMatchAllocation;
use App\Models\ReconciliationOutstandingItem;
use App\Models\StatementImportBatch;
use App\Models\StatementLine;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReconciliationService
{
    private const ACTIVE_MATCH_STATUSES = ['proposed', 'confirmed'];

    private const OUTSTANDING_CLASSIFICATIONS = [
        'BANK_FEE', 'INTEREST', 'EXTERNAL_DEPOSIT', 'EXTERNAL_WITHDRAWAL', 'UNIDENTIFIED', 'TIMING_DIFFERENCE',
        'DUPLICATE_STATEMENT_ITEM', 'DEPOSIT_IN_TRANSIT', 'OUTSTANDING_WITHDRAWAL', 'OUTSTANDING_CHECK', 'PENDING_TRANSFER', 'UNCLEARED_MOVEMENT', 'OTHER',
    ];

    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers, private readonly StatementImportService $imports) {}

    public function create(array $input, Company $company, Request $request): Reconciliation
    {
        $batch = StatementImportBatch::where('company_id', $company->id)->whereKey($input['statement_import_batch_id'] ?? null)->with('account.currency')->firstOrFail();
        if (! in_array($batch->status, ['validated', 'ready'], true)) {
            throw new RegistryConflictException('Only a validated statement batch can be reconciled.');
        }
        $account = $this->imports->account($batch->cash_account_id, $company, 'RECONCILE');
        if (($input['cash_account_id'] ?? $account->id) !== $account->id) {
            throw new RegistryConflictException('The statement batch and Cash Account do not match.');
        }
        if (Reconciliation::where('company_id', $company->id)->where('cash_account_id', $account->id)->where('period_start', '<=', $batch->period_end)->where('period_end', '>=', $batch->period_start)->whereNotIn('status', ['cancelled'])->exists()) {
            throw new RegistryConflictException('An overlapping reconciliation already exists for this Cash Account and period.');
        }
        $reconciliation = DB::transaction(function () use ($batch, $account, $company, $request) {
            $record = Reconciliation::create([
                'id' => (string) Str::uuid(), 'company_id' => $company->id, 'reconciliation_number' => $this->numbers->next($company->id, 'reconciliation'),
                'cash_account_id' => $account->id, 'currency_code' => $batch->currency_code, 'statement_import_batch_id' => $batch->id,
                'period_start' => $batch->period_start, 'period_end' => $batch->period_end, 'status' => 'draft', 'version' => 1,
                'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key'),
            ]);
            $this->history($record, null, 'draft', 'created', null, $company, $request);
            $this->recalculate($record, $company);
            $this->audit->record($request, 'cash-account.reconciliation.created', $record, $company->id, [], ['reconciliation_number' => $record->reconciliation_number, 'batch_id' => $batch->id], null, 'Reconciliation created', 'A reconciliation was created from a validated statement batch.');

            return $record;
        });

        return $this->load($reconciliation);
    }

    public function refresh(Reconciliation $reconciliation, Company $company, Request $request): Reconciliation
    {
        $this->scope($reconciliation, $company);
        $this->ensureEditable($reconciliation);
        $before = $reconciliation->population_fingerprint;
        $this->recalculate($reconciliation, $company);
        if ($before !== null && $before !== $reconciliation->fresh()->population_fingerprint) {
            $this->audit->record($request, 'cash-account.reconciliation.population-refreshed', $reconciliation, $company->id, ['population_fingerprint' => $before], ['population_fingerprint' => $reconciliation->fresh()->population_fingerprint], null, 'Reconciliation population refreshed', 'The internal movement population changed and was recalculated.');
        }

        return $this->load($reconciliation->fresh());
    }

    public function match(Reconciliation $reconciliation, array $input, Company $company, Request $request): ReconciliationMatch
    {
        $this->scope($reconciliation, $company);
        $this->ensureEditable($reconciliation);
        $lineIds = array_values(array_unique($input['statement_line_ids'] ?? []));
        $movementIds = array_values(array_unique($input['cash_movement_ids'] ?? []));
        if (! $lineIds || ! $movementIds || (count($lineIds) > 1 && count($movementIds) > 1)) {
            throw new RegistryConflictException('Matching supports one-to-one, one-to-many, and many-to-one allocations only.');
        }
        $method = strtolower((string) ($input['method'] ?? 'manual'));
        if (! in_array($method, ['exact', 'tolerance', 'manual', 'split', 'combined'], true)) {
            throw new RegistryConflictException('The matching method is not supported by MDS-700.');
        }
        $tolerance = max(0, (float) ($input['tolerance_amount'] ?? 0));
        $reason = trim((string) ($input['reason'] ?? ''));

        return DB::transaction(function () use ($reconciliation, $lineIds, $movementIds, $method, $tolerance, $reason, $company, $request) {
            $reconciliation = Reconciliation::whereKey($reconciliation->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $this->ensureEditable($reconciliation);
            $lines = StatementLine::where('company_id', $company->id)->where('statement_import_batch_id', $reconciliation->statement_import_batch_id)->whereIn('id', $lineIds)->lockForUpdate()->get();
            $movements = CashMovement::where('company_id', $company->id)->where('cash_account_id', $reconciliation->cash_account_id)->whereIn('id', $movementIds)->lockForUpdate()->get();
            if ($lines->count() !== count($lineIds) || $movements->count() !== count($movementIds)) {
                throw new RegistryConflictException('One or more match records are outside the reconciliation scope.');
            }
            foreach ($lines as $line) {
                if ($line->validation_status !== 'valid' || $line->currency_code !== $reconciliation->currency_code) {
                    throw new RegistryConflictException('Only valid statement lines in the reconciliation currency may be matched.');
                }
            }
            foreach ($movements as $movement) {
                if ($movement->movement_status !== 'posted' || $movement->reversal_movement_id || $movement->currency_code !== $reconciliation->currency_code || $movement->business_date->lt($reconciliation->period_start) || $movement->business_date->gt($reconciliation->period_end) || $movement->reconciliation_status === 'reconciled') {
                    throw new RegistryConflictException('Only eligible posted, unreconciled Cash Movements may be matched.');
                }
            }
            $lineRemaining = $this->remainingForLines($lines, $reconciliation->id);
            $movementRemaining = $this->remainingForMovements($movements, $reconciliation->id);
            $lineTotal = array_sum($lineRemaining);
            $movementTotal = array_sum($movementRemaining);
            $lineSign = $this->sign((float) $lines->first()->signed_amount);
            $movementSign = $this->sign($this->signedMovement($movements->first()));
            if ($lineSign !== $movementSign) {
                throw new RegistryConflictException('Statement and Cash Movement directions must agree.');
            }
            $difference = abs($lineTotal - $movementTotal);
            if ($method === 'exact' && $difference > 0.000001) {
                throw new RegistryConflictException('Exact matching requires equal remaining amounts.');
            }
            if ($difference > 0.000001) {
                if (! in_array($method, ['tolerance', 'manual', 'split', 'combined'], true) || $difference > $tolerance) {
                    throw new RegistryConflictException('The match difference exceeds the supplied tolerance.');
                }
                if (! $request->user()?->hasPermission('cash-accounts.reconciliation.matches.override-tolerance', $company->id)) {
                    throw new RegistryConflictException('Tolerance override permission is required for a non-zero difference.');
                }
                if ($reason === '') {
                    throw new RegistryConflictException('A reason is required for a tolerance override.');
                }
            }
            if ($method === 'split' && count($lineIds) !== 1) {
                throw new RegistryConflictException('Split matching requires one statement line and multiple Cash Movements.');
            }
            if ($method === 'combined' && count($movementIds) !== 1) {
                throw new RegistryConflictException('Combined matching requires multiple statement lines and one Cash Movement.');
            }
            $match = ReconciliationMatch::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'reconciliation_id' => $reconciliation->id, 'cash_account_id' => $reconciliation->cash_account_id, 'status' => 'confirmed', 'method' => $method, 'tolerance_amount' => number_format($tolerance, 6, '.', ''), 'difference_amount' => number_format($difference, 6, '.', ''), 'reason' => $reason ?: null, 'version' => 1, 'created_by' => $request->user()?->id, 'confirmed_by' => $request->user()?->id, 'confirmed_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id')]);
            foreach ($this->allocations($lineRemaining, $movementRemaining) as [$lineId, $movementId, $amount]) {
                ReconciliationMatchAllocation::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'reconciliation_id' => $reconciliation->id, 'reconciliation_match_id' => $match->id, 'statement_line_id' => $lineId, 'cash_movement_id' => $movementId, 'amount' => number_format($amount, 6, '.', '')]);
            }
            $this->syncStatuses($reconciliation, $company);
            $this->recalculate($reconciliation, $company);
            $this->audit->record($request, 'cash-account.reconciliation.match-confirmed', $match, $company->id, [], ['reconciliation_id' => $reconciliation->id, 'method' => $method, 'difference_amount' => $difference], $reason ?: null, 'Reconciliation match confirmed', 'Statement evidence was matched to posted Cash Movement allocations without changing ledger amounts.');

            return $match->load('allocations');
        });
    }

    public function unmatch(ReconciliationMatch $match, string $reason, Company $company, Request $request): ReconciliationMatch
    {
        if ((int) $match->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The match is outside the current company scope.');
        }
        if ($reason === '') {
            throw new RegistryConflictException('A reason is required to unmatch.');
        }
        $reconciliation = Reconciliation::whereKey($match->reconciliation_id)->where('company_id', $company->id)->firstOrFail();
        $this->ensureEditable($reconciliation);
        if ($match->status !== 'confirmed') {
            throw new RegistryConflictException('Only confirmed matches may be unmatched.');
        }
        $match->update(['status' => 'unmatched', 'unmatched_at' => now(), 'version' => $match->version + 1]);
        $this->syncStatuses($reconciliation, $company);
        $this->recalculate($reconciliation, $company);
        $this->audit->record($request, 'cash-account.reconciliation.match-unmatched', $match, $company->id, ['status' => 'confirmed'], ['status' => 'unmatched'], $reason, 'Reconciliation match removed', 'A match was unmatched with history preserved for rematching.');

        return $match->load('allocations');
    }

    public function outstanding(Reconciliation $reconciliation, array $input, Company $company, Request $request): ReconciliationOutstandingItem
    {
        $this->scope($reconciliation, $company);
        $this->ensureEditable($reconciliation);
        $classification = strtoupper((string) ($input['classification'] ?? ''));
        if (! in_array($classification, self::OUTSTANDING_CLASSIFICATIONS, true)) {
            throw new RegistryConflictException('The outstanding-item classification is not governed by MDS-700.');
        }
        $sourceType = (string) ($input['source_type'] ?? '');
        $sourceId = $input['source_id'] ?? null;
        if (! in_array($sourceType, ['statement_line', 'cash_movement'], true) || ! $sourceId) {
            throw new RegistryConflictException('An outstanding item requires one statement line or Cash Movement source.');
        }
        $line = $sourceType === 'statement_line' ? StatementLine::where('company_id', $company->id)->where('statement_import_batch_id', $reconciliation->statement_import_batch_id)->whereKey($sourceId)->firstOrFail() : null;
        $movement = $sourceType === 'cash_movement' ? CashMovement::where('company_id', $company->id)->where('cash_account_id', $reconciliation->cash_account_id)->whereKey($sourceId)->firstOrFail() : null;
        $amount = $line ? $this->remainingForLines(collect([$line]), $reconciliation->id)[$line->id] : $this->remainingForMovements(collect([$movement]), $reconciliation->id)[$movement->id];
        if ($amount <= 0.000001) {
            throw new RegistryConflictException('The source has no outstanding amount available for classification.');
        }
        $item = ReconciliationOutstandingItem::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'reconciliation_id' => $reconciliation->id, 'source_type' => $sourceType, 'statement_line_id' => $line?->id, 'cash_movement_id' => $movement?->id, 'classification' => $classification, 'status' => 'open', 'amount' => number_format($amount, 6, '.', ''), 'owner_id' => $input['owner_id'] ?? null, 'reason' => $input['reason'] ?? null, 'created_by' => $request->user()?->id, 'version' => 1]);
        $this->recalculate($reconciliation, $company);
        $this->audit->record($request, 'cash-account.reconciliation.outstanding-classified', $item, $company->id, [], ['source_type' => $sourceType, 'source_id' => $sourceId, 'classification' => $classification, 'amount' => $amount], null, 'Outstanding item classified', 'An unmatched statement or Cash Movement item was classified without a balance effect.');

        return $item;
    }

    public function transition(Reconciliation $reconciliation, string $action, ?string $reason, Company $company, Request $request): Reconciliation
    {
        $this->scope($reconciliation, $company);

        return DB::transaction(function () use ($reconciliation, $action, $reason, $company, $request) {
            $record = Reconciliation::whereKey($reconciliation->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $old = $record->status;
            if ($action === 'prepare') {
                if (! in_array($old, ['draft', 'returned', 'reopened'], true)) {
                    throw new RegistryConflictException('Only draft, returned, or reopened reconciliations may be prepared.');
                } $record->update(['status' => 'prepared', 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'version' => $record->version + 1]);
            } elseif ($action === 'submit') {
                if (! in_array($old, ['prepared', 'returned', 'reopened'], true)) {
                    throw new RegistryConflictException('Only prepared reconciliations may be submitted.');
                } $record->update(['status' => 'submitted', 'submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'version' => $record->version + 1]);
            } elseif ($action === 'review') {
                if ($old !== 'submitted') {
                    throw new RegistryConflictException('Only submitted reconciliations may enter review.');
                } $record->update(['status' => 'under_review', 'reviewed_by' => $request->user()?->id, 'reviewed_at' => now(), 'version' => $record->version + 1]);
            } elseif ($action === 'approve') {
                if ($old !== 'under_review') {
                    throw new RegistryConflictException('Only reviewed reconciliations may be approved.');
                } if ((int) $record->prepared_by === (int) $request->user()?->id) {
                    throw new RegistryConflictException('The preparer cannot approve the same reconciliation.', ['segregation' => true]);
                } $record->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $record->version + 1]);
            } elseif ($action === 'return') {
                if (! in_array($old, ['submitted', 'under_review', 'approved'], true) || trim((string) $reason) === '') {
                    throw new RegistryConflictException('A reviewed reconciliation can be returned only with a reason.');
                } $record->update(['status' => 'returned', 'return_reason' => $reason, 'version' => $record->version + 1]);
            } elseif ($action === 'complete') {
                $this->complete($record, $company, $request);

                return $record->fresh();
            } elseif ($action === 'reopen') {
                if ($old !== 'completed') {
                    throw new RegistryConflictException('Only completed reconciliations may be reopened.');
                } if (trim((string) $reason) === '') {
                    throw new RegistryConflictException('A reopening reason is required.');
                } $record->update(['status' => 'reopened', 'reopened_by' => $request->user()?->id, 'reopened_at' => now(), 'reopen_reason' => $reason, 'locked_at' => null, 'version' => $record->version + 1]);
            } elseif ($action === 'cancel') {
                if (in_array($old, ['completed', 'cancelled'], true)) {
                    throw new RegistryConflictException('This reconciliation cannot be cancelled.');
                } if (trim((string) $reason) === '') {
                    throw new RegistryConflictException('A cancellation reason is required.');
                } $record->update(['status' => 'cancelled', 'return_reason' => $reason, 'version' => $record->version + 1]);
            } else {
                throw new RegistryConflictException('The reconciliation action is not supported.');
            }
            $this->history($record, $old, $record->status, $action, $reason, $company, $request);
            $this->audit->record($request, 'cash-account.reconciliation.'.$action, $record, $company->id, ['status' => $old], ['status' => $record->status, 'version' => $record->version], $reason, 'Reconciliation '.$action, 'The reconciliation lifecycle changed under governed workflow controls.');

            return $record->fresh();
        });
    }

    public function candidates(Reconciliation $reconciliation, Company $company): array
    {
        $this->scope($reconciliation, $company);
        $this->ensureEditable($reconciliation);
        $lines = $reconciliation->batch->lines()->where('validation_status', 'valid')->get();
        $movements = $this->movementQuery($reconciliation)->get();

        return $lines->map(function (StatementLine $line) use ($movements, $reconciliation) {
            $remaining = $this->remainingForLines(collect([$line]), $reconciliation->id)[$line->id];

            return ['statement_line' => $line->only(['id', 'source_row_number', 'transaction_date', 'description', 'reference', 'signed_amount', 'currency_code']), 'remaining_amount' => $remaining, 'candidates' => $movements->filter(fn ($movement) => $this->sign((float) $line->signed_amount) === $this->sign($this->signedMovement($movement)) && abs(abs((float) $line->signed_amount) - abs((float) $movement->amount)) <= 0.000001 && abs($line->transaction_date->diffInDays($movement->business_date)) <= 7)->take(20)->values()->map(fn ($movement) => $movement->only(['id', 'business_date', 'direction', 'amount', 'currency_code', 'source_reference']))];
        })->values()->all();
    }

    public function load(Reconciliation $reconciliation): Reconciliation
    {
        return $reconciliation->load(['account.currency', 'batch.account', 'batch.lines', 'matches.allocations', 'outstandingItems', 'history', 'completions']);
    }

    private function complete(Reconciliation $record, Company $company, Request $request): void
    {
        if ($record->status !== 'approved') {
            throw new RegistryConflictException('Only approved reconciliations may be completed.');
        }
        $beforeFingerprint = $record->population_fingerprint;
        $this->recalculate($record, $company);
        $record->refresh();
        if ($beforeFingerprint !== $record->population_fingerprint) {
            throw new RegistryConflictException('The reconciliation population is stale; refresh and review it again.', ['stale_population' => true]);
        }
        if (abs((float) $record->difference_amount) > 0.000001) {
            throw new RegistryConflictException('The reconciliation difference must be zero before completion.');
        }
        $openSources = $this->unresolvedSources($record, $company);
        if ($openSources) {
            throw new RegistryConflictException('Every remaining statement line or Cash Movement must be matched or classified before completion.', ['unresolved_sources' => $openSources]);
        }
        $completion = ReconciliationCompletion::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'reconciliation_id' => $record->id, 'reconciliation_version' => $record->version + 1, 'status' => 'completed', 'difference_amount' => 0, 'population_fingerprint' => $record->population_fingerprint, 'completed_by' => $request->user()?->id, 'completed_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id')]);
        $record->update(['status' => 'completed', 'completed_by' => $request->user()?->id, 'completed_at' => now(), 'locked_at' => now(), 'version' => $record->version + 1]);
        $record->batch()->update(['status' => 'reconciled', 'version' => DB::raw('version + 1')]);
        $this->syncStatuses($record, $company);
        $this->history($record, 'approved', 'completed', 'completed', null, $company, $request, ['completion_id' => $completion->id]);
        $this->audit->record($request, 'cash-account.reconciliation.completed', $record, $company->id, [], ['completion_id' => $completion->id, 'locked' => true], null, 'Reconciliation completed', 'The reconciliation was atomically completed and its working records were locked.');
    }

    private function recalculate(Reconciliation $record, Company $company): void
    {
        $batch = $record->batch()->with('lines')->first();
        $lines = $batch->lines;
        $movements = $this->movementQuery($record)->get();
        $before = CashMovement::where('company_id', $company->id)->where('cash_account_id', $record->cash_account_id)->where('movement_status', 'posted')->where('business_date', '<', $record->period_start)->get();
        $stmtIn = $lines->sum(fn ($line) => (float) $line->credit_amount);
        $stmtOut = $lines->sum(fn ($line) => (float) $line->debit_amount);
        $opening = $batch->opening_statement_balance !== null ? (float) $batch->opening_statement_balance : 0.0;
        $closing = $batch->closing_statement_balance !== null ? (float) $batch->closing_statement_balance : $opening + $stmtIn - $stmtOut;
        $internalOpening = $before->sum(fn ($movement) => $this->signedMovement($movement));
        $internalIn = $movements->where('direction', 'increase')->sum('amount');
        $internalOut = $movements->where('direction', 'decrease')->sum('amount');
        $internalClosing = $internalOpening + (float) $internalIn - (float) $internalOut;
        $lineRemaining = $this->remainingForLines($lines, $record->id);
        $movementRemaining = $this->remainingForMovements($movements, $record->id);
        $matchedLines = array_sum(array_map(fn ($line) => abs((float) $line->signed_amount) - $lineRemaining[$line->id], $lines->all()));
        $matchedMovements = array_sum(array_map(fn ($movement) => (float) $movement->amount - $movementRemaining[$movement->id], $movements->all()));
        $outstandingStatements = $lines->sum(fn ($line) => ($this->hasOpenOutstanding($record->id, 'statement_line', $line->id) ? $lineRemaining[$line->id] : 0));
        $outstandingMovements = $movements->sum(fn ($movement) => ($this->hasOpenOutstanding($record->id, 'cash_movement', $movement->id) ? $movementRemaining[$movement->id] : 0));
        $fingerprint = hash('sha256', $movements->map(fn ($movement) => implode('|', [$movement->id, $movement->updated_at?->toIso8601String(), $movement->amount, $movement->direction, $movement->movement_status]))->implode(';'));
        $record->update(['statement_opening_balance' => number_format($opening, 6, '.', ''), 'statement_closing_balance' => number_format($closing, 6, '.', ''), 'statement_inflows' => number_format($stmtIn, 6, '.', ''), 'statement_outflows' => number_format($stmtOut, 6, '.', ''), 'internal_opening_balance' => number_format($internalOpening, 6, '.', ''), 'internal_closing_balance' => number_format($internalClosing, 6, '.', ''), 'internal_inflows' => number_format((float) $internalIn, 6, '.', ''), 'internal_outflows' => number_format((float) $internalOut, 6, '.', ''), 'matched_statement_amount' => number_format($matchedLines, 6, '.', ''), 'matched_movement_amount' => number_format($matchedMovements, 6, '.', ''), 'outstanding_statement_amount' => number_format($outstandingStatements, 6, '.', ''), 'outstanding_movement_amount' => number_format($outstandingMovements, 6, '.', ''), 'difference_amount' => number_format($closing - $internalClosing, 6, '.', ''), 'population_fingerprint' => $fingerprint]);
    }

    private function movementQuery(Reconciliation $record)
    {
        $currentMovementIds = ReconciliationMatchAllocation::where('reconciliation_id', $record->id)->whereNotNull('cash_movement_id')->select('cash_movement_id');

        return CashMovement::where('company_id', $record->company_id)->where('cash_account_id', $record->cash_account_id)->where('movement_status', 'posted')->whereBetween('business_date', [$record->period_start, $record->period_end])->whereNull('reversal_movement_id')->where(function ($query) use ($currentMovementIds) {
            $query->where('reconciliation_status', '<>', 'reconciled')->orWhereIn('id', $currentMovementIds);
        });
    }

    private function remainingForLines($lines, string $reconciliationId): array
    {
        $totals = ReconciliationMatchAllocation::where('reconciliation_id', $reconciliationId)->whereIn('statement_line_id', $lines->pluck('id'))->whereHas('reconciliationMatch', fn ($q) => $q->whereIn('status', self::ACTIVE_MATCH_STATUSES))->selectRaw('statement_line_id, SUM(amount) as total')->groupBy('statement_line_id')->pluck('total', 'statement_line_id');

        return $lines->mapWithKeys(fn ($line) => [$line->id => max(0, abs((float) $line->signed_amount) - (float) ($totals[$line->id] ?? 0))])->all();
    }

    private function remainingForMovements($movements, string $reconciliationId): array
    {
        $totals = ReconciliationMatchAllocation::where('reconciliation_id', $reconciliationId)->whereIn('cash_movement_id', $movements->pluck('id'))->whereHas('reconciliationMatch', fn ($q) => $q->whereIn('status', self::ACTIVE_MATCH_STATUSES))->selectRaw('cash_movement_id, SUM(amount) as total')->groupBy('cash_movement_id')->pluck('total', 'cash_movement_id');

        return $movements->mapWithKeys(fn ($movement) => [$movement->id => max(0, (float) $movement->amount - (float) ($totals[$movement->id] ?? 0))])->all();
    }

    private function allocations(array $lines, array $movements): array
    {
        $result = [];
        foreach ($lines as $lineId => $left) {
            foreach ($movements as $movementId => $right) {
                $amount = min($left, $right);
                if ($amount > 0.000001) {
                    $result[] = [$lineId, $movementId, $amount];
                    $lines[$lineId] -= $amount;
                    $movements[$movementId] -= $amount;
                }
            }
        }

        return $result;
    }

    private function syncStatuses(Reconciliation $record, Company $company): void
    {
        $lines = $record->batch()->with('lines')->first()->lines;
        $movements = $this->movementQuery($record)->get();
        $lineRemaining = $this->remainingForLines($lines, $record->id);
        $movementRemaining = $this->remainingForMovements($movements, $record->id);
        foreach ($lines as $line) {
            $line->update(['match_status' => $lineRemaining[$line->id] <= 0.000001 ? 'matched' : ($lineRemaining[$line->id] < abs((float) $line->signed_amount) ? 'partially_matched' : 'unmatched'), 'reconciliation_status' => $lineRemaining[$line->id] <= 0.000001 ? 'reconciled' : 'unreconciled']);
        }
        foreach ($movements as $movement) {
            $movement->update(['reconciliation_status' => $movementRemaining[$movement->id] <= 0.000001 ? 'reconciled' : 'unreconciled']);
        }
    }

    private function unresolvedSources(Reconciliation $record, Company $company): array
    {
        $lines = $record->batch()->with('lines')->first()->lines;
        $movements = $this->movementQuery($record)->get();
        $lineRemaining = $this->remainingForLines($lines, $record->id);
        $movementRemaining = $this->remainingForMovements($movements, $record->id);
        $unresolved = [];
        foreach ($lines as $line) {
            if ($lineRemaining[$line->id] > 0.000001 && ! $this->hasOpenOutstanding($record->id, 'statement_line', $line->id)) {
                $unresolved[] = ['source_type' => 'statement_line', 'source_id' => $line->id, 'amount' => $lineRemaining[$line->id]];
            }
        }
        foreach ($movements as $movement) {
            if ($movementRemaining[$movement->id] > 0.000001 && ! $this->hasOpenOutstanding($record->id, 'cash_movement', $movement->id)) {
                $unresolved[] = ['source_type' => 'cash_movement', 'source_id' => $movement->id, 'amount' => $movementRemaining[$movement->id]];
            }
        }

        return $unresolved;
    }

    private function hasOpenOutstanding(string $reconciliationId, string $sourceType, string $sourceId): bool
    {
        return ReconciliationOutstandingItem::where('reconciliation_id', $reconciliationId)->where('source_type', $sourceType)->where($sourceType === 'statement_line' ? 'statement_line_id' : 'cash_movement_id', $sourceId)->where('status', 'open')->exists();
    }

    private function signedMovement(CashMovement $movement): float
    {
        return $movement->direction === 'increase' ? (float) $movement->amount : -((float) $movement->amount);
    }

    private function sign(float $value): int
    {
        return $value < 0 ? -1 : 1;
    }

    private function ensureEditable(Reconciliation $reconciliation): void
    {
        if (in_array($reconciliation->status, ['completed', 'cancelled'], true) || $reconciliation->locked_at) {
            throw new RegistryConflictException('The reconciliation is locked and cannot be changed.');
        }
    }

    private function scope(Reconciliation $reconciliation, Company $company): void
    {
        if ((int) $reconciliation->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The reconciliation is outside the current company scope.');
        }
    }

    private function history(Reconciliation $record, ?string $from, string $to, string $event, ?string $reason, Company $company, Request $request, array $snapshot = []): void
    {
        ReconciliationHistory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'reconciliation_id' => $record->id, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'version' => $record->version, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'snapshot' => $snapshot ?: ['status' => $to]]);
    }
}
