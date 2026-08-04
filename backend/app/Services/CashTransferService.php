<?php

namespace App\Services;

use App\Events\CashMovementLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountingTransaction;
use App\Models\AccountingTransactionLine;
use App\Models\Branch;
use App\Models\BusinessTransaction;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashMovementPurpose;
use App\Models\CashMovementStatusHistory;
use App\Models\CashTransferDocument;
use App\Models\CashTransferLeg;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\ReasonCode;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class CashTransferService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers, private readonly CashMovementService $movements) {}

    public function create(array $input, Company $company, Request $request): CashTransferDocument
    {
        [$source, $destination] = $this->accounts($input['source_cash_account_id'] ?? null, $input['destination_cash_account_id'] ?? null, $company);
        $purpose = $this->purpose($input['purpose'] ?? null);
        $this->validateAccounts($source, $destination, $company);
        $this->validateCommon($input, $source, $destination, $purpose, $company);
        $this->ensureReference($input['external_reference'] ?? null, $company);
        $document = CashTransferDocument::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_number' => DB::transaction(fn () => $this->numbers->next($company->id, 'transfer')), 'purpose' => $purpose->code, 'source_cash_account_id' => $source->id, 'destination_cash_account_id' => $destination->id, 'currency_id' => $source->currency_id, 'amount' => $input['amount'], 'business_date' => $input['business_date'], 'expected_completion_date' => $input['expected_completion_date'] ?? null, 'payment_method_id' => $input['payment_method_id'] ?? null, 'branch_id' => $input['branch_id'] ?? null, 'reason_code_id' => $input['reason_code_id'], 'external_reference' => $input['external_reference'] ?? null, 'explanation' => $input['explanation'], 'supporting_reference' => $input['supporting_reference'] ?? null, 'status' => 'draft', 'version' => 1, 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
        $this->history($document, null, 'draft', $company, $request);
        $this->audit->record($request, 'cash-transfer.drafted', $document, $company->id, [], $this->safe($document), null, 'Cash Transfer drafted', 'A governed transfer draft was created.');

        return $this->load($document);
    }

    public function update(CashTransferDocument $document, array $input, Company $company, Request $request): CashTransferDocument
    {
        $this->scope($document, $company);
        if (! in_array($document->status, ['draft', 'returned'], true)) {
            throw new RegistryConflictException('Only draft or returned Cash Transfers may be edited.');
        }
        if ((int) ($input['version'] ?? 0) !== (int) $document->version) {
            throw new RegistryConflictException('This Cash Transfer was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $sourceId = $input['source_cash_account_id'] ?? $document->source_cash_account_id;
        $destinationId = $input['destination_cash_account_id'] ?? $document->destination_cash_account_id;
        [$source, $destination] = $this->accounts($sourceId, $destinationId, $company);
        $purpose = $this->purpose($input['purpose'] ?? $document->purpose);
        $data = $input + ['amount' => $document->amount, 'business_date' => $document->business_date?->toDateString(), 'reason_code_id' => $document->reason_code_id, 'explanation' => $document->explanation];
        $this->validateAccounts($source, $destination, $company);
        $this->validateCommon($data, $source, $destination, $purpose, $company);
        $document->update(array_merge(array_intersect_key($input, array_flip(['source_cash_account_id', 'destination_cash_account_id', 'amount', 'business_date', 'expected_completion_date', 'payment_method_id', 'branch_id', 'reason_code_id', 'external_reference', 'explanation', 'supporting_reference'])), ['purpose' => $purpose->code, 'currency_id' => $source->currency_id, 'status' => 'draft', 'version' => $document->version + 1, 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'return_reason' => null]));
        $this->history($document, $document->getOriginal('status'), 'draft', $company, $request);
        $this->audit->record($request, 'cash-transfer.updated', $document, $company->id, [], $this->safe($document), null, 'Cash Transfer updated', 'A governed transfer draft was updated.');

        return $this->load($document->refresh());
    }

    public function submit(CashTransferDocument $document, Company $company, Request $request): CashTransferDocument
    {
        $this->scope($document, $company);
        if (! in_array($document->status, ['draft', 'returned'], true)) {
            throw new RegistryConflictException('Only draft or returned Cash Transfers may be submitted.');
        }
        if (! $document->attachments()->exists()) {
            throw new RegistryConflictException('Transfer evidence is required before submission.', ['dependency' => 'evidence']);
        }
        $this->transition($document, 'submitted', $company, $request, ['submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'submitted_version' => $document->version + 1, 'version' => $document->version + 1, 'return_reason' => null]);

        return $this->load($document->refresh());
    }

    public function review(CashTransferDocument $document, Company $company, Request $request): CashTransferDocument
    {
        $this->scope($document, $company);
        if ($document->status !== 'submitted') {
            throw new RegistryConflictException('Only submitted Cash Transfers may enter review.');
        }
        $this->transition($document, 'under_review', $company, $request, ['reviewed_by' => $request->user()?->id, 'reviewed_at' => now(), 'submitted_version' => $document->version + 1, 'version' => $document->version + 1]);

        return $this->load($document->refresh());
    }

    public function approve(CashTransferDocument $document, Company $company, Request $request): CashTransferDocument
    {
        $this->scope($document, $company);
        if (! in_array($document->status, ['submitted', 'under_review'], true)) {
            throw new RegistryConflictException('Only submitted or reviewed Cash Transfers may be approved.');
        }
        if ((int) $document->prepared_by === (int) $request->user()?->id) {
            throw new RegistryConflictException('The preparer cannot approve the same Cash Transfer.', ['segregation' => true]);
        }
        if ((int) $document->submitted_version !== (int) $document->version) {
            throw new RegistryConflictException('The submitted Cash Transfer version is stale.', ['version_conflict' => true]);
        }
        $this->transition($document, 'approved', $company, $request, ['approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $document->version + 1]);

        return $this->load($document->refresh());
    }

    public function cancel(CashTransferDocument $document, string $reason, Company $company, Request $request): CashTransferDocument
    {
        $this->scope($document, $company);
        if (! in_array($document->status, ['draft', 'submitted', 'under_review', 'returned', 'approved'], true)) {
            throw new RegistryConflictException('Only unposted Cash Transfers may be cancelled.');
        }
        $this->requireReason($reason);
        $this->transition($document, 'cancelled', $company, $request, ['cancelled_by' => $request->user()?->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'version' => $document->version + 1]);

        return $this->load($document->refresh());
    }

    public function post(CashTransferDocument $document, Company $company, Request $request): CashTransferDocument
    {
        $this->scope($document, $company);
        $before = $this->safe($document);
        $negativeOverride = false;
        try {
            $result = DB::transaction(function () use ($document, $company, $request, &$negativeOverride) {
                $locked = CashTransferDocument::whereKey($document->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'approved') {
                    throw new RegistryConflictException('Only approved Cash Transfers may be posted.');
                }
                $accounts = CashAccount::whereIn('id', [$locked->source_cash_account_id, $locked->destination_cash_account_id])->where('company_id', $company->id)->with('currency')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $source = $accounts->get($locked->source_cash_account_id);
                $destination = $accounts->get($locked->destination_cash_account_id);
                if (! $source || ! $destination) {
                    throw new RegistryConflictException('Both Cash Transfer accounts must belong to the current company.');
                }
                $purpose = $this->purpose($locked->purpose);
                $this->validateAccounts($source, $destination, $company);
                $this->validateCommon(['amount' => $locked->amount, 'business_date' => $locked->business_date?->toDateString(), 'reason_code_id' => $locked->reason_code_id, 'payment_method_id' => $locked->payment_method_id, 'branch_id' => $locked->branch_id], $source, $destination, $purpose, $company);
                if (CashTransferLeg::where('transfer_document_id', $locked->id)->exists()) {
                    throw new RegistryConflictException('This Cash Transfer has already been posted.');
                }
                $negativeOverride = $this->movements->checkAvailableBalance($source, (string) $locked->amount, $company, $request);
                if ($negativeOverride && ! $request->boolean('negative_balance_override')) {
                    $negativeOverride = false;
                }
                if ($negativeOverride && ! trim((string) $request->input('negative_balance_reason'))) {
                    throw new RegistryConflictException('A reason is required for a negative-balance override.', ['negative_balance' => true]);
                }
                $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => $locked->purpose, 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $locked->business_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key')]);
                $accounting = AccountingTransaction::create(['id' => (string) Str::uuid(), 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => $locked->purpose, 'status' => 'posted', 'business_date' => $locked->business_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
                $amount = (string) $locked->amount;
                AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accounting->id, 'account_title_id' => $source->account_title_id, 'debit' => '0', 'credit' => $amount, 'currency_code' => $source->currency->code, 'description' => 'Cash Transfer source outflow']);
                AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accounting->id, 'account_title_id' => $destination->account_title_id, 'debit' => $amount, 'credit' => '0', 'currency_code' => $source->currency->code, 'description' => 'Cash Transfer destination inflow']);
                $sourceMovement = $this->movement($company, $source, 'decrease', $amount, $locked, $accounting->id, $request, 'EVT-CAS-008');
                $destinationMovement = $this->movement($company, $destination, 'increase', $amount, $locked, $accounting->id, $request, 'EVT-CAS-008');
                CashTransferLeg::create(['id' => (string) Str::uuid(), 'transfer_document_id' => $locked->id, 'cash_movement_id' => $sourceMovement->id, 'cash_account_id' => $source->id, 'direction' => 'decrease']);
                CashTransferLeg::create(['id' => (string) Str::uuid(), 'transfer_document_id' => $locked->id, 'cash_movement_id' => $destinationMovement->id, 'cash_account_id' => $destination->id, 'direction' => 'increase']);
                $locked->update(['status' => 'posted', 'posted_by' => $request->user()?->id, 'source_movement_id' => $sourceMovement->id, 'destination_movement_id' => $destinationMovement->id, 'accounting_transaction_id' => $accounting->id, 'version' => $locked->version + 1]);

                return $locked->refresh();
            });
        } catch (RegistryConflictException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'negative')) {
                $this->audit->record($request, 'cash-transfer.negative-balance-attempted', $document, $company->id, $before, $before, null, 'Negative balance attempt', 'A transfer was blocked by available-balance policy.');
            }
            throw $exception;
        }
        DB::afterCommit(function () use ($request, $result, $company, $before, $negativeOverride) {
            $this->audit->record($request, 'cash-transfer.posted', $result, $company->id, $before, $this->safe($result), null, 'Cash Transfer posted', 'Both transfer legs and their balanced accounting effect were posted atomically.');
            if ($negativeOverride) {
                $this->audit->record($request, 'cash-transfer.negative-balance.override-used', $result, $company->id, [], ['document_id' => $result->id], $request->input('negative_balance_reason'), 'Negative balance override used', 'A governed negative-balance override was used on a transfer.');
            }
            Event::dispatch(new CashMovementLifecycleEvent($result->purpose.'.posted', $company->id, CashTransferDocument::class, $result->id));
        });

        return $this->load($result);
    }

    public function reverse(CashTransferDocument $document, string $reason, Company $company, Request $request): CashTransferDocument
    {
        $this->scope($document, $company);
        if ($document->status !== 'posted' || $document->reversal_document_id) {
            throw new RegistryConflictException('Only an unreversed posted Cash Transfer may be reversed.');
        }
        $this->requireReason($reason);
        $before = $this->safe($document);
        $result = DB::transaction(function () use ($document, $reason, $company, $request) {
            $original = CashTransferDocument::whereKey($document->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($original->status !== 'posted' || $original->reversal_document_id) {
                throw new RegistryConflictException('This Cash Transfer has already been reversed.');
            }
            $accounts = CashAccount::whereIn('id', [$original->source_cash_account_id, $original->destination_cash_account_id])->where('company_id', $company->id)->with('currency')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $oldSource = $accounts->get($original->source_cash_account_id);
            $oldDestination = $accounts->get($original->destination_cash_account_id);
            if (! $oldSource || ! $oldDestination) {
                throw new RegistryConflictException('The original transfer accounts are outside the current company.');
            }
            $reversal = CashTransferDocument::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_number' => $this->numbers->next($company->id, 'transfer_reversal'), 'purpose' => $original->purpose.'_REVERSAL', 'source_cash_account_id' => $oldDestination->id, 'destination_cash_account_id' => $oldSource->id, 'currency_id' => $original->currency_id, 'amount' => $original->amount, 'business_date' => now()->toDateString(), 'payment_method_id' => $original->payment_method_id, 'branch_id' => $original->branch_id, 'reason_code_id' => $original->reason_code_id, 'external_reference' => 'Reversal of '.$original->document_number, 'explanation' => $reason, 'status' => 'approved', 'version' => 1, 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'posted_by' => $request->user()?->id, 'original_document_id' => $original->id, 'reversal_reason' => $reason, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => $reversal->purpose, 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $reversal->business_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key')]);
            $accounting = AccountingTransaction::create(['id' => (string) Str::uuid(), 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => $reversal->purpose, 'status' => 'posted', 'business_date' => $reversal->business_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $amount = (string) $reversal->amount;
            AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accounting->id, 'account_title_id' => $oldDestination->account_title_id, 'debit' => '0', 'credit' => $amount, 'currency_code' => $oldSource->currency->code, 'description' => 'Cash Transfer reversal source outflow']);
            AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accounting->id, 'account_title_id' => $oldSource->account_title_id, 'debit' => $amount, 'credit' => '0', 'currency_code' => $oldSource->currency->code, 'description' => 'Cash Transfer reversal destination inflow']);
            $sourceMovement = $this->movement($company, $oldDestination, 'decrease', $amount, $reversal, $accounting->id, $request, 'EVT-CAS-008-REVERSAL');
            $destinationMovement = $this->movement($company, $oldSource, 'increase', $amount, $reversal, $accounting->id, $request, 'EVT-CAS-008-REVERSAL');
            CashTransferLeg::create(['id' => (string) Str::uuid(), 'transfer_document_id' => $reversal->id, 'cash_movement_id' => $sourceMovement->id, 'cash_account_id' => $oldDestination->id, 'direction' => 'decrease']);
            CashTransferLeg::create(['id' => (string) Str::uuid(), 'transfer_document_id' => $reversal->id, 'cash_movement_id' => $destinationMovement->id, 'cash_account_id' => $oldSource->id, 'direction' => 'increase']);
            $reversal->update(['status' => 'posted', 'posted_at' => now(), 'source_movement_id' => $sourceMovement->id, 'destination_movement_id' => $destinationMovement->id, 'accounting_transaction_id' => $accounting->id, 'version' => 2]);
            $original->update(['status' => 'reversed', 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'reversal_document_id' => $reversal->id, 'version' => $original->version + 1]);
            CashMovement::whereKey($original->source_movement_id)->update(['reversal_movement_id' => $sourceMovement->id]);
            CashMovement::whereKey($original->destination_movement_id)->update(['reversal_movement_id' => $destinationMovement->id]);

            return $reversal->refresh();
        });
        DB::afterCommit(function () use ($request, $result, $company, $before, $reason) {
            $this->audit->record($request, 'cash-transfer.reversed', $result, $company->id, $before, $this->safe($result), $reason, 'Cash Transfer reversed', 'Both original transfer legs were countered atomically.');
            Event::dispatch(new CashMovementLifecycleEvent('transfer.reversed', $company->id, CashTransferDocument::class, $result->id));
        });

        return $this->load($result);
    }

    public function load(CashTransferDocument $document): CashTransferDocument
    {
        return $document->load(['sourceAccount.currency', 'destinationAccount.currency', 'currency', 'paymentMethod', 'reasonCode', 'legs.movement', 'attachments']);
    }

    private function purpose(?string $code): CashMovementPurpose
    {
        $purpose = CashMovementPurpose::where('code', $code)->where('document_kind', 'transfer')->where('status', 'active')->first();
        if (! $purpose) {
            throw new RegistryConflictException('The selected transfer purpose is not permitted.');
        }

        return $purpose;
    }

    private function accounts(?string $sourceId, ?string $destinationId, Company $company): array
    {
        if (! $sourceId || ! $destinationId || $sourceId === $destinationId) {
            throw new RegistryConflictException('Transfer source and destination must be different.');
        }
        $accounts = CashAccount::whereIn('id', [$sourceId, $destinationId])->where('company_id', $company->id)->with('currency')->get()->keyBy('id');
        if (! $accounts->has($sourceId) || ! $accounts->has($destinationId)) {
            throw new RegistryConflictException('Both transfer accounts must belong to the current company.');
        }

        return [$accounts->get($sourceId), $accounts->get($destinationId)];
    }

    private function validateAccounts(CashAccount $source, CashAccount $destination, Company $company): void
    {
        if ($source->status !== 'active' || $destination->status !== 'active') {
            throw new RegistryConflictException('Both transfer accounts must be active.');
        }
        if ((string) $source->currency_id !== (string) $destination->currency_id) {
            throw new RegistryConflictException('Cross-currency transfers are not supported in Phase 3B.', ['currency' => 'same_currency_required']);
        }
        if (! $source->capabilities()->where('capability', 'TRANSFER_OUT')->where('enabled', true)->exists()) {
            throw new RegistryConflictException('The source Cash Account does not support TRANSFER_OUT.');
        }
        if (! $destination->capabilities()->where('capability', 'TRANSFER_IN')->where('enabled', true)->exists()) {
            throw new RegistryConflictException('The destination Cash Account does not support TRANSFER_IN.');
        }
        if ((int) $source->company_id !== (int) $company->id || (int) $destination->company_id !== (int) $company->id) {
            throw new RegistryConflictException('Transfer accounts must belong to the current company.');
        }
    }

    private function validateCommon(array $input, CashAccount $source, CashAccount $destination, CashMovementPurpose $purpose, Company $company): void
    {
        if (! isset($input['amount']) || ! is_numeric($input['amount']) || (float) $input['amount'] <= 0) {
            throw new RegistryConflictException('Transfer amount must be greater than zero.');
        }
        if (! ($input['business_date'] ?? null) || $input['business_date'] > now()->toDateString()) {
            throw new RegistryConflictException('Transfer business date cannot be in the future.');
        }
        $lockDate = $company->cash_movement_lock_date ?? $company->opening_balance_lock_date;
        if ($lockDate && $input['business_date'] <= $lockDate->toDateString()) {
            throw new RegistryConflictException('The transfer business date is within the locked date range.', ['dependency' => 'lock_date']);
        }
        if (! empty($input['payment_method_id'])) {
            $payment = PaymentMethod::where('company_id', $company->id)->whereKey($input['payment_method_id'])->where('status', 'active')->first();
            if (! $payment || ! $payment->supports_outgoing) {
                throw new RegistryConflictException('The transfer Payment Method is not active or valid for outgoing use.');
            }
        }
        if (! empty($input['branch_id']) && ! Branch::where('company_id', $company->id)->whereKey($input['branch_id'])->where('status', 'active')->exists()) {
            throw new RegistryConflictException('The Branch must be active and belong to the current company.');
        }
        if (! empty($input['reason_code_id']) && ! ReasonCode::where('company_id', $company->id)->whereKey($input['reason_code_id'])->where('domain', $purpose->reason_domain)->where('status', 'active')->exists()) {
            throw new RegistryConflictException("An active same-company {$purpose->reason_domain} Reason Code is required.");
        }
        if ((string) $source->currency_id !== (string) ($input['currency_id'] ?? $source->currency_id)) {
            throw new RegistryConflictException('The transfer currency must match both Cash Accounts.');
        }
    }

    private function movement(Company $company, CashAccount $account, string $direction, string $amount, CashTransferDocument $document, string $accountingId, Request $request, string $event): CashMovement
    {
        return CashMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id, 'direction' => $direction, 'amount' => $amount, 'currency_code' => $account->currency->code, 'business_date' => $document->business_date, 'posted_at' => now(), 'source_event_type' => $event, 'source_record_type' => CashTransferDocument::class, 'source_record_id' => $document->id, 'source_reference' => $document->document_number, 'movement_status' => 'posted', 'clearing_status' => 'not_applicable', 'reconciliation_status' => 'unreconciled', 'accounting_transaction_id' => $accountingId, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
    }

    private function ensureReference(?string $reference, Company $company): void
    {
        if ($reference && CashTransferDocument::where('company_id', $company->id)->where('external_reference', $reference)->whereNotIn('status', ['cancelled', 'reversed'])->exists()) {
            throw new RegistryConflictException('The transfer external reference is already used.', ['duplicate_reference' => true]);
        }
    }

    private function transition(CashTransferDocument $document, string $status, Company $company, Request $request, array $updates): void
    {
        $from = $document->status;
        $document->update(array_merge($updates, ['status' => $status]));
        $this->history($document, $from, $status, $company, $request, $updates['return_reason'] ?? $updates['cancellation_reason'] ?? null);
        $this->audit->record($request, 'cash-transfer.'.strtolower($status), $document, $company->id, [], $this->safe($document), null, 'Cash Transfer '.$status, 'Cash Transfer workflow status changed.');
    }

    private function history(CashTransferDocument $document, ?string $from, string $to, Company $company, Request $request, ?string $reason = null): void
    {
        CashMovementStatusHistory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_type' => CashTransferDocument::class, 'document_id' => $document->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function scope(CashTransferDocument $document, Company $company): void
    {
        if ((int) $document->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The Cash Transfer is outside the current company scope.');
        }
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new RegistryConflictException('A reason is required for this Cash Transfer action.');
        }
    }

    private function safe(CashTransferDocument $document): array
    {
        return ['id' => $document->id, 'document_number' => $document->document_number, 'status' => $document->status, 'source_cash_account_id' => $document->source_cash_account_id, 'destination_cash_account_id' => $document->destination_cash_account_id, 'amount' => (string) $document->amount, 'purpose' => $document->purpose, 'source_movement_id' => $document->source_movement_id, 'destination_movement_id' => $document->destination_movement_id, 'accounting_transaction_id' => $document->accounting_transaction_id];
    }
}
