<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\AccountingTransaction;
use App\Models\AccountingTransactionLine;
use App\Models\AccountTitle;
use App\Models\BusinessTransaction;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\OpeningBalance;
use App\Models\ReasonCode;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OpeningBalanceService
{
    public function __construct(private readonly AuditService $audit, private readonly CashAccountService $accounts) {}

    public function create(array $input, Company $company, Request $request): OpeningBalance
    {
        $account = $this->account($input['cash_account_id'] ?? null, $company);
        $this->validateAccountCurrency($account, $input['currency_id'] ?? null);
        $this->validateReason($input['reason_code_id'] ?? null, $company);
        $this->validateDate($input['effective_date'] ?? null, $company);
        $this->validateAmount($input);
        if (OpeningBalance::where('cash_account_id', $account->id)->whereIn('status', ['draft', 'submitted', 'approved', 'posted'])->exists()) {
            throw new RegistryConflictException('This Cash Account already has an active or posted Opening Balance.', ['dependency' => 'opening_balance']);
        }
        $opening = OpeningBalance::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id, 'currency_id' => $input['currency_id'], 'effective_date' => $input['effective_date'], 'direction' => $input['direction'] ?? 'increase', 'amount' => $input['amount'], 'opening_source' => $input['opening_source'], 'migration_reference' => $input['migration_reference'] ?? null, 'offset_account_title_id' => $input['offset_account_title_id'] ?? $company->opening_balance_offset_account_title_id, 'reason_code_id' => $input['reason_code_id'], 'explanation' => $input['explanation'], 'batch_reference' => $input['batch_reference'] ?? null, 'status' => 'draft', 'version' => 1, 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
        $this->audit->record($request, 'opening-balance.created', $opening, $company->id, [], $this->safe($opening), null, 'Opening Balance created', 'An Opening Balance draft was created.');

        return $opening->load(['account.currency', 'reasonCode', 'attachments']);
    }

    public function update(OpeningBalance $opening, array $input, Company $company, Request $request): OpeningBalance
    {
        $this->scope($opening, $company);
        if ($opening->status !== 'draft' && $opening->status !== 'returned') {
            throw new RegistryConflictException('Only draft or returned Opening Balances may be edited.');
        }
        $this->version($opening, $input);
        $account = $this->account($input['cash_account_id'] ?? $opening->cash_account_id, $company);
        $currencyId = $input['currency_id'] ?? $opening->currency_id;
        $this->validateAccountCurrency($account, $currencyId);
        $this->validateReason($input['reason_code_id'] ?? $opening->reason_code_id, $company);
        $this->validateDate($input['effective_date'] ?? $opening->effective_date?->toDateString(), $company);
        $this->validateAmount($input + ['amount' => $input['amount'] ?? $opening->amount, 'direction' => $input['direction'] ?? $opening->direction]);
        $updates = array_intersect_key($input, array_flip(['cash_account_id', 'currency_id', 'effective_date', 'direction', 'amount', 'opening_source', 'migration_reference', 'offset_account_title_id', 'reason_code_id', 'explanation', 'batch_reference']));
        $updates['version'] = $opening->version + 1;
        $updates['prepared_by'] = $request->user()?->id;
        $updates['prepared_at'] = now();
        $updates['return_reason'] = null;
        $opening->update($updates);
        $this->audit->record($request, 'opening-balance.updated', $opening, $company->id, [], $this->safe($opening), null, 'Opening Balance updated', 'An Opening Balance draft was updated.');

        return $opening->refresh()->load(['account.currency', 'reasonCode', 'attachments']);
    }

    public function submit(OpeningBalance $opening, Company $company, Request $request): OpeningBalance
    {
        $this->scope($opening, $company);
        if ($opening->status !== 'draft' && $opening->status !== 'returned') {
            throw new RegistryConflictException('Only draft or returned Opening Balances may be submitted.');
        }
        if (! $opening->attachments()->exists()) {
            throw new RegistryConflictException('Opening Balance evidence is required before submission.', ['dependency' => 'evidence']);
        }
        $this->validateDate($opening->effective_date?->toDateString(), $company);
        $opening->update(['status' => 'submitted', 'submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'version' => $opening->version + 1, 'return_reason' => null]);
        $this->audit->record($request, 'opening-balance.submitted', $opening, $company->id, [], $this->safe($opening), null, 'Opening Balance submitted', 'An Opening Balance was submitted for review.');

        return $opening->refresh();
    }

    public function approve(OpeningBalance $opening, Company $company, Request $request): OpeningBalance
    {
        $this->scope($opening, $company);
        if ($opening->status !== 'submitted') {
            throw new RegistryConflictException('Only submitted Opening Balances may be approved.');
        }
        if ((int) $opening->prepared_by === (int) $request->user()?->id) {
            throw new RegistryConflictException('The preparer cannot approve the same Opening Balance under segregation policy.', ['segregation' => true]);
        }
        $opening->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $opening->version + 1]);
        $this->audit->record($request, 'opening-balance.approved', $opening, $company->id, [], $this->safe($opening), null, 'Opening Balance approved', 'An Opening Balance was approved for posting.');

        return $opening->refresh();
    }

    public function returnForCorrection(OpeningBalance $opening, string $reason, Company $company, Request $request): OpeningBalance
    {
        $this->scope($opening, $company);
        if (! in_array($opening->status, ['submitted', 'approved'], true)) {
            throw new RegistryConflictException('Only submitted or approved Opening Balances may be returned.');
        }
        if (trim($reason) === '') {
            throw new RegistryConflictException('A reason is required to return an Opening Balance.');
        }
        $opening->update(['status' => 'returned', 'returned_by' => $request->user()?->id, 'returned_at' => now(), 'return_reason' => $reason, 'version' => $opening->version + 1]);
        $this->audit->record($request, 'opening-balance.returned', $opening, $company->id, [], $this->safe($opening), $reason, 'Opening Balance returned', 'An Opening Balance was returned for correction.');

        return $opening->refresh();
    }

    public function post(OpeningBalance $opening, Company $company, Request $request): OpeningBalance
    {
        $this->scope($opening, $company);
        $before = $this->safe($opening);
        $result = DB::transaction(function () use ($opening, $company, $request) {
            $locked = OpeningBalance::whereKey($opening->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'approved') {
                throw new RegistryConflictException('Only approved Opening Balances may be posted.');
            }
            $account = CashAccount::whereKey($locked->cash_account_id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($account->status !== 'active') {
                throw new RegistryConflictException('The Cash Account must be active before its Opening Balance can be posted.');
            }
            $offset = $this->offset($company, $locked->offset_account_title_id);
            if ($locked->currency_id !== $account->currency_id) {
                throw new RegistryConflictException('The Opening Balance currency does not match the Cash Account currency.');
            }
            if ($locked->posted_at || CashMovement::where('source_record_type', OpeningBalance::class)->where('source_record_id', $locked->id)->where('movement_status', 'posted')->exists()) {
                throw new RegistryConflictException('This Opening Balance has already been posted.');
            }
            $transaction = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'opening_balance', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $locked->effective_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key')]);
            $accounting = AccountingTransaction::create(['id' => (string) Str::uuid(), 'business_transaction_id' => $transaction->id, 'company_id' => $company->id, 'transaction_type' => 'opening_balance', 'status' => 'posted', 'business_date' => $locked->effective_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $amount = (string) $locked->amount;
            $currency = $account->currency()->firstOrFail()->code;
            $cashDebit = $locked->direction === 'increase' ? $amount : '0';
            $cashCredit = $locked->direction === 'decrease' ? $amount : '0';
            $offsetDebit = $cashCredit;
            $offsetCredit = $cashDebit;
            AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accounting->id, 'account_title_id' => $account->account_title_id, 'debit' => $cashDebit, 'credit' => $cashCredit, 'currency_code' => $currency, 'description' => 'Opening Balance Cash Account effect']);
            AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accounting->id, 'account_title_id' => $offset->id, 'debit' => $offsetDebit, 'credit' => $offsetCredit, 'currency_code' => $currency, 'description' => 'Opening Balance offset effect']);
            $movement = CashMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id, 'direction' => $locked->direction, 'amount' => $amount, 'currency_code' => $currency, 'business_date' => $locked->effective_date, 'posted_at' => now(), 'source_event_type' => 'EVT-CAS-005', 'source_record_type' => OpeningBalance::class, 'source_record_id' => $locked->id, 'source_reference' => $locked->migration_reference ?: $locked->batch_reference, 'movement_status' => 'posted', 'clearing_status' => 'not_applicable', 'reconciliation_status' => 'not_applicable', 'accounting_transaction_id' => $accounting->id, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $locked->update(['status' => 'posted', 'posted_by' => $request->user()?->id, 'posted_at' => now(), 'business_transaction_id' => $transaction->id, 'cash_movement_id' => $movement->id, 'version' => $locked->version + 1]);
            $account->update(['last_activity_at' => now(), 'updated_by' => $request->user()?->id, 'version' => $account->version + 1]);

            return $locked->refresh();
        });
        DB::afterCommit(function () use ($request, $result, $company, $before) {
            $this->audit->record($request, 'EVT-CAS-005', $result, $company->id, $before, $this->safe($result), null, 'Opening Balance posted', 'The approved Opening Balance was posted atomically with its Cash Movement and accounting effect.');
        });

        return $result->load(['account.currency', 'reasonCode', 'attachments', 'movement']);
    }

    public function reverse(OpeningBalance $opening, string $reason, Company $company, Request $request): OpeningBalance
    {
        $this->scope($opening, $company);
        if ($opening->status !== 'posted' || ! $opening->cash_movement_id) {
            throw new RegistryConflictException('Only a posted Opening Balance may be reversed.');
        }
        if (trim($reason) === '') {
            throw new RegistryConflictException('A reason is required to reverse an Opening Balance.');
        }
        $before = $this->safe($opening);
        $result = DB::transaction(function () use ($opening, $reason, $company, $request) {
            $locked = OpeningBalance::whereKey($opening->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'posted' || ! $locked->cash_movement_id) {
                throw new RegistryConflictException('This Opening Balance is already reversed or not posted.');
            }
            $original = CashMovement::whereKey($locked->cash_movement_id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($original->reversal_movement_id) {
                throw new RegistryConflictException('This Opening Balance has already been reversed.');
            }
            $account = CashAccount::whereKey($locked->cash_account_id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $offset = $this->offset($company, $locked->offset_account_title_id);
            $transaction = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'opening_balance_reversal', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => now()->toDateString(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key')]);
            $accounting = AccountingTransaction::create(['id' => (string) Str::uuid(), 'business_transaction_id' => $transaction->id, 'company_id' => $company->id, 'transaction_type' => 'opening_balance_reversal', 'status' => 'posted', 'business_date' => now()->toDateString(), 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $currency = $account->currency()->firstOrFail()->code;
            $inverse = $original->direction === 'increase' ? 'decrease' : 'increase';
            $cashDebit = $inverse === 'increase' ? (string) $original->amount : '0';
            $cashCredit = $inverse === 'decrease' ? (string) $original->amount : '0';
            AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accounting->id, 'account_title_id' => $account->account_title_id, 'debit' => $cashDebit, 'credit' => $cashCredit, 'currency_code' => $currency, 'description' => 'Opening Balance reversal Cash Account effect']);
            AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accounting->id, 'account_title_id' => $offset->id, 'debit' => $cashCredit, 'credit' => $cashDebit, 'currency_code' => $currency, 'description' => 'Opening Balance reversal offset effect']);
            $counter = CashMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id, 'direction' => $inverse, 'amount' => $original->amount, 'currency_code' => $currency, 'business_date' => now()->toDateString(), 'posted_at' => now(), 'source_event_type' => 'EVT-CAS-005-REVERSAL', 'source_record_type' => OpeningBalance::class, 'source_record_id' => $locked->id, 'source_reference' => 'Reversal of '.$original->id, 'movement_status' => 'posted', 'clearing_status' => 'not_applicable', 'reconciliation_status' => 'not_applicable', 'original_movement_id' => $original->id, 'accounting_transaction_id' => $accounting->id, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $original->update(['reversal_movement_id' => $counter->id]);
            $locked->update(['status' => 'reversed', 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'version' => $locked->version + 1]);
            $account->update(['last_activity_at' => now(), 'updated_by' => $request->user()?->id, 'version' => $account->version + 1]);

            return $locked->refresh();
        });
        DB::afterCommit(function () use ($request, $result, $company, $before, $reason) {
            $this->audit->record($request, 'opening-balance.reversed', $result, $company->id, $before, $this->safe($result), $reason, 'Opening Balance reversed', 'A posted Opening Balance was reversed through a linked counter-movement.');
        });

        return $result->load(['account.currency', 'reasonCode', 'attachments', 'movement']);
    }

    private function account(?string $id, Company $company): CashAccount
    {
        return CashAccount::where('company_id', $company->id)->whereKey($id)->with(['currency', 'type'])->firstOrFail();
    }

    private function offset(Company $company, ?string $requested): AccountTitle
    {
        $configured = $company->opening_balance_offset_account_title_id;
        if (! $configured || ($requested && $requested !== $configured)) {
            throw new RegistryConflictException('Opening Balance posting is blocked because the company offset Account Title is not configured or does not match policy.', ['dependency' => 'opening_balance_offset_account_title']);
        }
        $offset = AccountTitle::where('company_id', $company->id)->whereKey($configured)->where('status', 'active')->first();
        if (! $offset) {
            throw new RegistryConflictException('The configured Opening Balance offset Account Title is not active in the current company.', ['dependency' => 'opening_balance_offset_account_title']);
        }

        return $offset;
    }

    private function validateAccountCurrency(CashAccount $account, ?string $currencyId): void
    {
        if (! $currencyId || $account->currency_id !== $currencyId) {
            throw new RegistryConflictException('The Opening Balance currency must match the Cash Account currency.');
        }
    }

    private function validateReason(?string $id, Company $company): void
    {
        if (! $id || ! ReasonCode::where('company_id', $company->id)->whereKey($id)->where('domain', 'OPENING_BALANCE')->where('status', 'active')->exists()) {
            throw new RegistryConflictException('An active same-company OPENING_BALANCE Reason Code is required.');
        }
    }

    private function validateDate(?string $date, Company $company): void
    {
        if (! $date || $date > now()->toDateString()) {
            throw new RegistryConflictException('Opening Balance effective date cannot be in the future.');
        }
        if ($company->opening_balance_lock_date && $date <= $company->opening_balance_lock_date->toDateString()) {
            throw new RegistryConflictException('The Opening Balance effective date is within a locked date range.', ['dependency' => 'lock_date']);
        }
    }

    private function validateAmount(array $input): void
    {
        if (! isset($input['amount']) || ! is_numeric($input['amount']) || (float) $input['amount'] <= 0) {
            throw new RegistryConflictException('Opening Balance amount must be greater than zero.');
        }
        if (! in_array($input['direction'] ?? 'increase', ['increase', 'decrease'], true)) {
            throw new RegistryConflictException('Opening Balance direction is invalid.');
        }
    }

    private function version(OpeningBalance $opening, array $input): void
    {
        if ((int) ($input['version'] ?? 0) !== (int) $opening->version) {
            throw new RegistryConflictException('This Opening Balance was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
    }

    private function scope(OpeningBalance $opening, Company $company): void
    {
        if ((int) $opening->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The Opening Balance is outside the current company scope.');
        }
    }

    private function safe(OpeningBalance $opening): array
    {
        return ['id' => $opening->id, 'cash_account_id' => $opening->cash_account_id, 'amount' => (string) $opening->amount, 'direction' => $opening->direction, 'status' => $opening->status, 'version' => $opening->version];
    }
}
