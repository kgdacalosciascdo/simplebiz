<?php

namespace App\Services;

use App\Events\CashMovementLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountingTransaction;
use App\Models\AccountingTransactionLine;
use App\Models\AccountTitle;
use App\Models\Branch;
use App\Models\BusinessTransaction;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashMovementDocument;
use App\Models\CashMovementPurpose;
use App\Models\CashMovementStatusHistory;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\ReasonCode;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class CashMovementService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers) {}

    public function create(array $input, Company $company, Request $request, string $kind): CashMovementDocument
    {
        $purpose = $this->purpose($input['movement_purpose'] ?? null, $kind);
        $account = $this->account($input['cash_account_id'] ?? null, $company);
        $direction = $purpose->direction;
        $this->validateAccount($account, $purpose->required_capability, $company);
        $this->validateCommon($input, $company, $account, $purpose, $direction);
        $this->ensureUniqueReference($input['external_reference'] ?? null, $company, $input['source_type'] ?? null);
        $document = CashMovementDocument::create([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_number' => DB::transaction(fn () => $this->numbers->next($company->id, $kind)), 'purpose_id' => $purpose->id, 'source_type' => $input['source_type'], 'source_record_type' => $input['source_record_type'] ?? null, 'source_record_id' => $input['source_record_id'] ?? null, 'external_reference' => $input['external_reference'] ?? null, 'cash_account_id' => $account->id, 'currency_id' => $account->currency_id, 'direction' => $direction, 'amount' => $input['amount'], 'business_date' => $input['business_date'], 'payment_method_id' => $input['payment_method_id'] ?? null, 'branch_id' => $input['branch_id'] ?? null, 'offset_account_title_id' => $input['offset_account_title_id'], 'reason_code_id' => $input['reason_code_id'], 'explanation' => $input['explanation'], 'supporting_reference' => $input['supporting_reference'] ?? null, 'status' => 'draft', 'version' => 1, 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key'),
        ]);
        $this->history($document, null, 'draft', $company, $request);
        $this->audit->record($request, 'cash-movement.drafted', $document, $company->id, [], $this->safe($document), null, 'Cash Movement drafted', 'A governed Cash Movement draft was created.');

        return $this->load($document);
    }

    public function update(CashMovementDocument $document, array $input, Company $company, Request $request): CashMovementDocument
    {
        $this->scope($document, $company);
        if (! in_array($document->status, ['draft', 'returned'], true)) {
            throw new RegistryConflictException('Only draft or returned Cash Movements may be edited.');
        }
        $this->version($document, $input);
        $purpose = $this->purpose($input['movement_purpose'] ?? $document->purpose->code, $document->purpose->document_kind);
        $account = $this->account($input['cash_account_id'] ?? $document->cash_account_id, $company);
        $data = $input + ['amount' => $document->amount, 'business_date' => $document->business_date?->toDateString(), 'source_type' => $document->source_type, 'reason_code_id' => $document->reason_code_id, 'explanation' => $document->explanation, 'offset_account_title_id' => $document->offset_account_title_id];
        $this->validateAccount($account, $purpose->required_capability, $company);
        $this->validateCommon($data, $company, $account, $purpose, $purpose->direction);
        $document->update(array_merge(array_intersect_key($input, array_flip(['source_type', 'source_record_type', 'source_record_id', 'external_reference', 'cash_account_id', 'business_date', 'amount', 'payment_method_id', 'branch_id', 'offset_account_title_id', 'reason_code_id', 'explanation', 'supporting_reference'])), ['purpose_id' => $purpose->id, 'direction' => $purpose->direction, 'currency_id' => $account->currency_id, 'status' => 'draft', 'version' => $document->version + 1, 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'return_reason' => null]));
        $this->history($document, $document->getOriginal('status'), 'draft', $company, $request);
        $this->audit->record($request, 'cash-movement.updated', $document, $company->id, [], $this->safe($document), null, 'Cash Movement updated', 'A governed Cash Movement draft was updated.');

        return $this->load($document->refresh());
    }

    public function submit(CashMovementDocument $document, Company $company, Request $request): CashMovementDocument
    {
        $this->scope($document, $company);
        if (! in_array($document->status, ['draft', 'returned'], true)) {
            throw new RegistryConflictException('Only draft or returned Cash Movements may be submitted.');
        }
        if ($document->purpose->requires_evidence && ! $document->attachments()->exists()) {
            throw new RegistryConflictException('Cash Movement evidence is required before submission.', ['dependency' => 'evidence']);
        }
        $this->transition($document, 'submitted', $company, $request, ['submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'submitted_version' => $document->version + 1, 'version' => $document->version + 1, 'return_reason' => null]);

        return $this->load($document->refresh());
    }

    public function review(CashMovementDocument $document, Company $company, Request $request): CashMovementDocument
    {
        $this->scope($document, $company);
        if ($document->status !== 'submitted') {
            throw new RegistryConflictException('Only submitted Cash Movements may enter review.');
        }
        $this->transition($document, 'under_review', $company, $request, ['reviewed_by' => $request->user()?->id, 'reviewed_at' => now(), 'submitted_version' => $document->version + 1, 'version' => $document->version + 1]);

        return $this->load($document->refresh());
    }

    public function approve(CashMovementDocument $document, Company $company, Request $request): CashMovementDocument
    {
        $this->scope($document, $company);
        if (! in_array($document->status, ['submitted', 'under_review'], true)) {
            throw new RegistryConflictException('Only submitted or reviewed Cash Movements may be approved.');
        }
        if ((int) $document->prepared_by === (int) $request->user()?->id) {
            throw new RegistryConflictException('The preparer cannot approve the same Cash Movement.', ['segregation' => true]);
        }
        if ((int) $document->submitted_version !== (int) $document->version && $document->status === 'submitted') {
            throw new RegistryConflictException('The submitted Cash Movement version is stale.', ['version_conflict' => true]);
        }
        $this->transition($document, 'approved', $company, $request, ['approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $document->version + 1]);

        return $this->load($document->refresh());
    }

    public function cancel(CashMovementDocument $document, string $reason, Company $company, Request $request): CashMovementDocument
    {
        $this->scope($document, $company);
        if (! in_array($document->status, ['draft', 'submitted', 'under_review', 'returned', 'approved'], true)) {
            throw new RegistryConflictException('Only unposted Cash Movements may be cancelled.');
        }
        $this->requireReason($reason);
        $this->transition($document, 'cancelled', $company, $request, ['cancelled_by' => $request->user()?->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'version' => $document->version + 1]);

        return $this->load($document->refresh());
    }

    public function post(CashMovementDocument $document, Company $company, Request $request): CashMovementDocument
    {
        $this->scope($document, $company);
        $before = $this->safe($document);
        $negativeOverride = false;
        try {
            $result = DB::transaction(function () use ($document, $company, $request, &$negativeOverride) {
                $locked = CashMovementDocument::whereKey($document->id)->where('company_id', $company->id)->lockForUpdate()->with('purpose')->firstOrFail();
                if ($locked->status !== 'approved') {
                    throw new RegistryConflictException('Only approved Cash Movements may be posted.');
                }
                $account = CashAccount::whereKey($locked->cash_account_id)->where('company_id', $company->id)->lockForUpdate()->with('currency')->firstOrFail();
                $purpose = $locked->purpose;
                $this->validateAccount($account, $purpose->required_capability, $company);
                $this->validatePostingReferences($locked, $company, $account);
                if ($locked->cash_movement_id || CashMovement::where('source_record_type', CashMovementDocument::class)->where('source_record_id', $locked->id)->where('movement_status', 'posted')->exists()) {
                    throw new RegistryConflictException('This Cash Movement has already been posted.');
                }
                if ($locked->direction === 'decrease') {
                    $negativeOverride = $this->checkAvailableBalance($account, (string) $locked->amount, $company, $request);
                }
                $offset = $this->offset($locked->offset_account_title_id, $company);
                $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => $locked->purpose->code, 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $locked->business_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key')]);
                $accounting = AccountingTransaction::create(['id' => (string) Str::uuid(), 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => $locked->purpose->code, 'status' => 'posted', 'business_date' => $locked->business_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
                $this->lines($accounting->id, $account->account_title_id, $offset->id, (string) $locked->amount, $locked->direction, $account->currency->code, 'Direct Cash Movement');
                $movement = CashMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id, 'direction' => $locked->direction, 'amount' => $locked->amount, 'currency_code' => $account->currency->code, 'business_date' => $locked->business_date, 'posted_at' => now(), 'source_event_type' => $locked->purpose->code === 'DIRECT_CASH_IN' ? 'EVT-CAS-006' : 'EVT-CAS-007', 'source_record_type' => CashMovementDocument::class, 'source_record_id' => $locked->id, 'source_reference' => $locked->document_number, 'movement_status' => 'posted', 'clearing_status' => $locked->purpose->clearing_mode, 'reconciliation_status' => 'unreconciled', 'accounting_transaction_id' => $accounting->id, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
                $locked->update(['status' => 'posted', 'posted_by' => $request->user()?->id, 'posted_at' => now(), 'cash_movement_id' => $movement->id, 'accounting_transaction_id' => $accounting->id, 'negative_balance_override' => $negativeOverride, 'negative_balance_reason' => $negativeOverride ? $request->input('negative_balance_reason') : null, 'version' => $locked->version + 1]);

                return $locked->refresh();
            });
        } catch (RegistryConflictException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'negative')) {
                $this->audit->record($request, 'cash-movement.negative-balance-attempted', $document, $company->id, $before, $before, null, 'Negative balance attempt', 'A Cash Out posting was blocked by available-balance policy.');
            }
            throw $exception;
        }
        DB::afterCommit(function () use ($request, $result, $company, $before, $negativeOverride) {
            $this->audit->record($request, 'cash-movement.posted', $result, $company->id, $before, $this->safe($result), null, 'Cash Movement posted', 'A Direct Cash Movement and balanced accounting effect were posted atomically.');
            if ($negativeOverride) {
                $this->audit->record($request, 'cash-movement.negative-balance.override-used', $result, $company->id, [], ['document_id' => $result->id], $result->negative_balance_reason, 'Negative balance override used', 'A governed negative-balance override was used.');
            }
            Event::dispatch(new CashMovementLifecycleEvent($result->purpose->code === 'DIRECT_CASH_IN' ? 'cash-in.posted' : 'cash-out.posted', $company->id, CashMovementDocument::class, $result->id));
        });

        return $this->load($result);
    }

    public function reverse(CashMovementDocument $document, string $reason, Company $company, Request $request): CashMovementDocument
    {
        $this->scope($document, $company);
        if ($document->status !== 'posted' || ! $document->cash_movement_id || $document->reversal_document_id) {
            throw new RegistryConflictException('Only an unreversed posted Cash Movement may be reversed.');
        }
        $this->requireReason($reason);
        $before = $this->safe($document);
        $result = DB::transaction(function () use ($document, $reason, $company, $request) {
            $original = CashMovementDocument::whereKey($document->id)->where('company_id', $company->id)->lockForUpdate()->with('purpose')->firstOrFail();
            if ($original->status !== 'posted' || $original->reversal_document_id) {
                throw new RegistryConflictException('This Cash Movement has already been reversed.');
            }
            $account = CashAccount::whereKey($original->cash_account_id)->where('company_id', $company->id)->lockForUpdate()->with('currency')->firstOrFail();
            $originalMovement = CashMovement::whereKey($original->cash_movement_id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $reversalPurpose = CashMovementPurpose::where('code', $original->purpose->code === 'DIRECT_CASH_IN' ? 'DIRECT_CASH_IN_REVERSAL' : 'DIRECT_CASH_OUT_REVERSAL')->firstOrFail();
            $reversalNumber = $this->numbers->next($company->id, $original->purpose->document_kind.'_reversal');
            $reversal = CashMovementDocument::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_number' => $reversalNumber, 'purpose_id' => $reversalPurpose->id, 'source_type' => 'DIRECT_MOVEMENT_REVERSAL', 'source_record_type' => CashMovementDocument::class, 'source_record_id' => $original->id, 'external_reference' => 'Reversal of '.$original->document_number, 'cash_account_id' => $account->id, 'currency_id' => $original->currency_id, 'direction' => $original->direction === 'increase' ? 'decrease' : 'increase', 'amount' => $original->amount, 'business_date' => now()->toDateString(), 'payment_method_id' => $original->payment_method_id, 'branch_id' => $original->branch_id, 'offset_account_title_id' => $original->offset_account_title_id, 'reason_code_id' => $original->reason_code_id, 'explanation' => $reason, 'status' => 'approved', 'version' => 1, 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'posted_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'original_document_id' => $original->id, 'reversal_reason' => $reason, 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $offset = $this->offset($reversal->offset_account_title_id, $company);
            $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => $reversalPurpose->code, 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $reversal->business_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key')]);
            $accounting = AccountingTransaction::create(['id' => (string) Str::uuid(), 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => $reversalPurpose->code, 'status' => 'posted', 'business_date' => $reversal->business_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $this->lines($accounting->id, $account->account_title_id, $offset->id, (string) $reversal->amount, $reversal->direction, $account->currency->code, 'Direct Cash Movement reversal');
            $counter = CashMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id, 'direction' => $reversal->direction, 'amount' => $reversal->amount, 'currency_code' => $account->currency->code, 'business_date' => $reversal->business_date, 'posted_at' => now(), 'source_event_type' => 'EVT-CAS-006-REVERSAL', 'source_record_type' => CashMovementDocument::class, 'source_record_id' => $reversal->id, 'source_reference' => $reversal->document_number, 'movement_status' => 'posted', 'clearing_status' => 'not_applicable', 'reconciliation_status' => 'unreconciled', 'original_movement_id' => $originalMovement->id, 'accounting_transaction_id' => $accounting->id, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $originalMovement->update(['reversal_movement_id' => $counter->id]);
            $reversal->update(['status' => 'posted', 'posted_at' => now(), 'cash_movement_id' => $counter->id, 'accounting_transaction_id' => $accounting->id, 'version' => 2]);
            $original->update(['status' => 'reversed', 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'reversal_document_id' => $reversal->id, 'version' => $original->version + 1]);

            return $reversal->refresh();
        });
        DB::afterCommit(function () use ($request, $result, $company, $before, $reason) {
            $this->audit->record($request, 'cash-movement.reversed', $result, $company->id, $before, $this->safe($result), $reason, 'Cash Movement reversed', 'A posted Cash Movement was reversed with a linked counter-movement.');
            Event::dispatch(new CashMovementLifecycleEvent('cash-movement.reversed', $company->id, CashMovementDocument::class, $result->id));
        });

        return $this->load($result);
    }

    public function load(CashMovementDocument $document): CashMovementDocument
    {
        return $document->load(['purpose', 'account.currency', 'paymentMethod', 'reasonCode', 'movement', 'attachments']);
    }

    private function purpose(?string $code, string $kind): CashMovementPurpose
    {
        $purpose = CashMovementPurpose::where('code', $code)->where('document_kind', $kind)->where('status', 'active')->first();
        if (! $purpose) {
            throw new RegistryConflictException('The selected Cash Movement purpose is not permitted for this workflow.', ['movement_purpose' => $code]);
        }

        return $purpose;
    }

    private function account(?string $id, Company $company): CashAccount
    {
        return CashAccount::where('company_id', $company->id)->whereKey($id)->with('currency')->firstOrFail();
    }

    private function validateAccount(CashAccount $account, ?string $capability, Company $company): void
    {
        if ($account->status !== 'active') {
            throw new RegistryConflictException('The Cash Account must be active for this workflow.');
        }
        if ($capability && ! $account->capabilities()->where('capability', $capability)->where('enabled', true)->exists()) {
            throw new RegistryConflictException('The Cash Account does not have the required capability.', ['capability' => $capability]);
        }
        if ((int) $account->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The Cash Account is outside the current company scope.');
        }
    }

    private function validateCommon(array $input, Company $company, CashAccount $account, CashMovementPurpose $purpose, string $direction): void
    {
        if (! isset($input['amount']) || ! is_numeric($input['amount']) || (float) $input['amount'] <= 0) {
            throw new RegistryConflictException('Cash Movement amount must be greater than zero.');
        }
        if (! $input['business_date'] || $input['business_date'] > now()->toDateString()) {
            throw new RegistryConflictException('Business date cannot be in the future.');
        }
        $lockDate = $company->cash_movement_lock_date ?? $company->opening_balance_lock_date;
        if ($lockDate && $input['business_date'] <= $lockDate->toDateString()) {
            throw new RegistryConflictException('The business date is within the locked date range.', ['dependency' => 'lock_date']);
        }
        if (! in_array($direction, ['increase', 'decrease'], true)) {
            throw new RegistryConflictException('Cash Movement direction is invalid.');
        }
        if (! $input['source_type'] || ! in_array($input['source_type'], $purpose->allowed_source_types ?? [], true)) {
            throw new RegistryConflictException('The source type is not permitted for this Cash Movement purpose.', ['source_type' => $input['source_type'] ?? null]);
        }
        $this->reason($input['reason_code_id'] ?? null, $purpose->reason_domain, $company);
        $this->offset($input['offset_account_title_id'] ?? null, $company);
        if (! empty($input['payment_method_id'])) {
            $this->paymentMethod($input['payment_method_id'], $company, $direction);
        }
        if (! empty($input['branch_id']) && ! Branch::where('company_id', $company->id)->whereKey($input['branch_id'])->where('status', 'active')->exists()) {
            throw new RegistryConflictException('The Branch must be active and belong to the current company.');
        }
        if ((string) $account->currency_id !== (string) ($input['currency_id'] ?? $account->currency_id)) {
            throw new RegistryConflictException('The movement currency must match the Cash Account currency.');
        }
    }

    private function validatePostingReferences(CashMovementDocument $document, Company $company, CashAccount $account): void
    {
        $this->validateCommon(['amount' => $document->amount, 'business_date' => $document->business_date?->toDateString(), 'source_type' => $document->source_type, 'reason_code_id' => $document->reason_code_id, 'offset_account_title_id' => $document->offset_account_title_id, 'payment_method_id' => $document->payment_method_id, 'branch_id' => $document->branch_id, 'currency_id' => $document->currency_id], $company, $account, $document->purpose, $document->direction);
    }

    private function reason(?string $id, string $domain, Company $company): void
    {
        if (! $id || ! ReasonCode::where('company_id', $company->id)->whereKey($id)->where('domain', $domain)->where('status', 'active')->exists()) {
            throw new RegistryConflictException("An active same-company {$domain} Reason Code is required.", ['dependency' => 'reason_code']);
        }
    }

    private function offset(?string $id, Company $company): AccountTitle
    {
        $offset = AccountTitle::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->first();
        if (! $offset) {
            throw new RegistryConflictException('An active same-company offset Account Title is required.', ['dependency' => 'offset_account_title']);
        }

        return $offset;
    }

    private function paymentMethod(string $id, Company $company, string $direction): PaymentMethod
    {
        $payment = PaymentMethod::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->first();
        if (! $payment || ($direction === 'increase' && ! $payment->supports_incoming) || ($direction === 'decrease' && ! $payment->supports_outgoing)) {
            throw new RegistryConflictException('The Payment Method is not active, same-company, or valid for this direction.');
        }

        return $payment;
    }

    private function ensureUniqueReference(?string $reference, Company $company, ?string $sourceType): void
    {
        if (! $reference) {
            return;
        }
        if (CashMovementDocument::where('company_id', $company->id)->where('external_reference', $reference)->whereNotIn('status', ['cancelled', 'reversed'])->exists()) {
            throw new RegistryConflictException('The external reference is already used by a Cash Movement.', ['duplicate_reference' => true, 'source_type' => $sourceType]);
        }
    }

    public function checkAvailableBalance(CashAccount $account, string $amount, Company $company, Request $request): bool
    {
        $balance = (string) CashMovement::where('cash_account_id', $account->id)->where('company_id', $company->id)->where('movement_status', 'posted')->selectRaw("COALESCE(SUM(CASE WHEN direction = 'increase' THEN amount ELSE -amount END), 0) as total")->value('total');
        if ($this->compare($balance, $amount) >= 0) {
            return false;
        }
        $override = (bool) $company->allow_negative_cash_balance && $account->capabilities()->where('capability', 'ALLOW_NEGATIVE_BALANCE')->where('enabled', true)->exists() && $request->user()?->hasPermission('cash-accounts.negative-balance.override', $company->id);
        if (! $override || ! $request->boolean('negative_balance_override') || ! trim((string) $request->input('negative_balance_reason'))) {
            throw new RegistryConflictException('Posting would create an unauthorized negative available balance.', ['negative_balance' => true, 'override_required' => true]);
        }

        return true;
    }

    private function lines(string $accountingId, string $cashTitleId, string $offsetId, string $amount, string $direction, string $currency, string $description): void
    {
        $cashDebit = $direction === 'increase' ? $amount : '0';
        $cashCredit = $direction === 'decrease' ? $amount : '0';
        AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accountingId, 'account_title_id' => $cashTitleId, 'debit' => $cashDebit, 'credit' => $cashCredit, 'currency_code' => $currency, 'description' => $description.' Cash Account effect']);
        AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accountingId, 'account_title_id' => $offsetId, 'debit' => $cashCredit, 'credit' => $cashDebit, 'currency_code' => $currency, 'description' => $description.' offset effect']);
    }

    private function transition(CashMovementDocument $document, string $status, Company $company, Request $request, array $updates): void
    {
        $from = $document->status;
        $document->update(array_merge($updates, ['status' => $status]));
        $this->history($document, $from, $status, $company, $request, $updates['return_reason'] ?? $updates['cancellation_reason'] ?? null);
        $this->audit->record($request, 'cash-movement.'.strtolower($status), $document, $company->id, [], $this->safe($document), $updates['return_reason'] ?? $updates['cancellation_reason'] ?? null, 'Cash Movement '.$status, 'Cash Movement workflow status changed.');
    }

    private function history(CashMovementDocument $document, ?string $from, string $to, Company $company, Request $request, ?string $reason = null): void
    {
        CashMovementStatusHistory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_type' => CashMovementDocument::class, 'document_id' => $document->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function scope(CashMovementDocument $document, Company $company): void
    {
        if ((int) $document->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The Cash Movement is outside the current company scope.');
        }
    }

    private function version(CashMovementDocument $document, array $input): void
    {
        if ((int) ($input['version'] ?? 0) !== (int) $document->version) {
            throw new RegistryConflictException('This Cash Movement was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new RegistryConflictException('A reason is required for this Cash Movement action.');
        }
    }

    private function compare(string $left, string $right): int
    {
        $normalize = function (string $value): array {
            $value = trim($value);
            $negative = str_starts_with($value, '-');
            $value = ltrim($value, '+-');
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $fraction = rtrim($fraction, '0');
            $whole = ltrim($whole, '0') ?: '0';

            return [$negative, $whole, $fraction];
        };
        [$leftNegative, $leftWhole, $leftFraction] = $normalize($left);
        [$rightNegative, $rightWhole, $rightFraction] = $normalize($right);
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $sign = $leftNegative ? -1 : 1;
        if (strlen($leftWhole) !== strlen($rightWhole)) {
            return (strlen($leftWhole) > strlen($rightWhole) ? 1 : -1) * $sign;
        }
        if ($leftWhole !== $rightWhole) {
            return ($leftWhole > $rightWhole ? 1 : -1) * $sign;
        }
        $length = max(strlen($leftFraction), strlen($rightFraction));
        $leftFraction = str_pad($leftFraction, $length, '0');
        $rightFraction = str_pad($rightFraction, $length, '0');
        if ($leftFraction === $rightFraction) {
            return 0;
        }

        return ($leftFraction > $rightFraction ? 1 : -1) * $sign;
    }

    private function safe(CashMovementDocument $document): array
    {
        return ['id' => $document->id, 'document_number' => $document->document_number, 'status' => $document->status, 'cash_account_id' => $document->cash_account_id, 'direction' => $document->direction, 'amount' => (string) $document->amount, 'purpose' => $document->purpose?->code, 'cash_movement_id' => $document->cash_movement_id, 'accounting_transaction_id' => $document->accounting_transaction_id];
    }
}
