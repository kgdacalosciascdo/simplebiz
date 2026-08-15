<?php

namespace App\Services;

use App\Events\CashAccountLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashAccountCapability;
use App\Models\CashAccountCustodian;
use App\Models\CashAccountInstitution;
use App\Models\CashAccountType;
use App\Models\Company;
use App\Models\ReferenceCurrency;
use App\Models\User;
use App\Support\AuditService;
use App\Support\CashAccountCapabilityCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CashAccountService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(array $input, Company $company, Request $request): CashAccount
    {
        $type = $this->type($input['cash_account_type_id'] ?? null);
        if (CashAccount::where('company_id', $company->id)->where('code', trim($input['code']))->exists()) {
            throw new RegistryConflictException('A Cash Account with this code already exists in the company.');
        }
        $this->validateReferences($input, $company, $type);
        $institution = $this->institution($input['institution'] ?? null, $company, $request);
        $identifier = $input['account_identifier'] ?? null;
        $capabilities = $this->capabilities($input['capabilities'] ?? null, $type);

        $account = CashAccount::create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'code' => trim($input['code']),
            'name' => trim($input['name']),
            'display_name' => $input['display_name'] ?? null,
            'cash_account_type_id' => $type->id,
            'account_title_id' => $input['account_title_id'],
            'currency_id' => $input['currency_id'],
            'branch_id' => $input['branch_id'] ?? null,
            'institution_id' => $institution?->id,
            'account_identifier_encrypted' => $identifier,
            'account_identifier_last4' => $identifier ? substr($identifier, -4) : null,
            'masked_account_identifier' => $this->mask($identifier),
            'external_reference' => $input['external_reference'] ?? null,
            'account_subtype' => $input['account_subtype'] ?? null,
            'notes' => $input['notes'] ?? null,
            'status' => 'draft',
            'version' => 1,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
            'source_channel' => $request->input('source_channel', 'api'),
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);
        $this->storeCapabilities($account, $capabilities, $request->user()?->id);
        $this->audit($request, 'EVT-CAS-001', $account, 'Cash Account created', 'A draft Cash Account profile was created.');

        return $account->load(['type', 'accountTitle', 'currency', 'branch', 'institution', 'capabilities', 'custodians']);
    }

    public function update(CashAccount $account, array $input, Company $company, Request $request): CashAccount
    {
        $this->scope($account, $company);
        if ((int) ($input['version'] ?? 0) !== (int) $account->version) {
            throw new RegistryConflictException('This Cash Account was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        if (! in_array($account->status, ['draft', 'active'], true)) {
            throw new RegistryConflictException('Only draft or active Cash Account profiles may be edited.');
        }
        $type = $this->type($input['cash_account_type_id'] ?? $account->cash_account_type_id);
        $financiallyUsed = DB::table('cash_movements')->where('cash_account_id', $account->id)->exists()
            || DB::table('opening_balances')->where('cash_account_id', $account->id)->where('status', 'posted')->exists();
        $requestedTitle = $input['account_title_id'] ?? $account->account_title_id;
        $requestedCurrency = $input['currency_id'] ?? $account->currency_id;
        if ($financiallyUsed && ($requestedTitle !== $account->account_title_id || $requestedCurrency !== $account->currency_id)) {
            throw new RegistryConflictException('The Account Title and Currency cannot be changed after a Cash Account has financial history.', ['dependency' => 'financial_usage', 'account_id' => $account->id]);
        }
        $this->validateReferences([...$account->only(['account_title_id', 'currency_id', 'branch_id']), ...$input], $company, $type);
        $institution = array_key_exists('institution', $input) ? $this->institution($input['institution'], $company, $request) : $account->institution;
        $identifier = array_key_exists('account_identifier', $input) ? $input['account_identifier'] : $account->account_identifier_encrypted;
        $updates = array_intersect_key($input, array_flip(['code', 'name', 'display_name', 'account_title_id', 'currency_id', 'branch_id', 'external_reference', 'account_subtype', 'notes']));
        if (isset($updates['code']) && CashAccount::where('company_id', $company->id)->where('code', trim($updates['code']))->where('id', '!=', $account->id)->exists()) {
            throw new RegistryConflictException('A Cash Account with this code already exists in the company.');
        }
        if (array_key_exists('account_identifier', $input)) {
            $updates['account_identifier_encrypted'] = $identifier;
            $updates['account_identifier_last4'] = $identifier ? substr($identifier, -4) : null;
            $updates['masked_account_identifier'] = $this->mask($identifier);
        }
        $updates['cash_account_type_id'] = $type->id;
        $updates['institution_id'] = $institution?->id;
        $updates['updated_by'] = $request->user()?->id;
        $updates['version'] = $account->version + 1;
        if (CashAccount::where('company_id', $company->id)->whereKey($account->id)->where('version', $account->version)->update($updates) !== 1) {
            throw new RegistryConflictException('This Cash Account was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $account->refresh();
        $this->audit($request, 'EVT-CAS-002', $account, 'Cash Account updated', 'A Cash Account profile was updated.');

        return $account->load(['type', 'accountTitle', 'currency', 'branch', 'institution', 'capabilities', 'custodians']);
    }

    public function closureBlockers(CashAccount $account, Company $company): array
    {
        $this->scope($account, $company);
        $account->loadMissing('currency');
        $blockers = [];
        $postedBalance = DB::table('cash_movements')->where('cash_account_id', $account->id)->where('movement_status', 'posted')->selectRaw("COALESCE(SUM(CASE WHEN direction = 'increase' THEN amount ELSE -amount END), 0) as total")->value('total');
        if (abs((float) $postedBalance) > 0.000001) {
            $blockers[] = ['code' => 'nonzero_balance', 'message' => 'The posted Cash Account balance must be zero before closure.', 'amount' => (string) $postedBalance, 'currency' => $account->currency?->code];
        }
        $this->addCountBlocker($blockers, 'pending_movements', 'cash_movement_documents', $account->id, ['draft', 'submitted', 'under_review', 'approved'], 'Unresolved Cash Movement documents must be completed or cancelled before closure.');
        $this->addCountBlocker($blockers, 'pending_transfers', 'cash_transfer_documents', $account->id, ['draft', 'submitted', 'under_review', 'approved'], 'Unresolved Cash Transfer documents must be completed or cancelled before closure.', ['source_cash_account_id', 'destination_cash_account_id']);
        $this->addCountBlocker($blockers, 'pending_counts', 'cash_counts', $account->id, ['scheduled', 'in_progress', 'submitted', 'under_review', 'variance_review'], 'Open Cash Counts must be closed or resolved before closure.');
        $this->addCountBlocker($blockers, 'pending_reconciliations', 'reconciliations', $account->id, ['draft', 'prepared', 'submitted', 'under_review', 'approved', 'returned', 'reopened'], 'Open reconciliations must be completed or cancelled before closure.');
        $this->addCountBlocker($blockers, 'open_statement_imports', 'statement_import_batches', $account->id, ['uploaded', 'validation_failed', 'ready'], 'Statement imports must be cancelled or reconciled before closure.');
        $pendingChecks = DB::table('payment_instruments as instrument')
            ->join('payment_instructions as payment', 'payment.id', '=', 'instrument.payment_instruction_id')
            ->where('instrument.cash_account_id', $account->id)
            ->where('instrument.instrument_type', 'check')
            ->whereIn('instrument.status', ['reserved', 'printed', 'signed', 'released'])
            ->whereIn('payment.status', ['draft', 'pending_approval', 'approved', 'scheduled', 'ready', 'released', 'pending_confirmation'])
            ->count();
        if ($pendingChecks > 0) {
            $blockers[] = ['code' => 'pending_checks', 'count' => $pendingChecks, 'message' => 'Pending checks must be released, voided, stopped, or otherwise resolved before closure.'];
        }
        $openOutstanding = DB::table('reconciliation_outstanding_items')->where('status', 'open')->where(function ($query) use ($account) {
            $query->whereIn('reconciliation_id', DB::table('reconciliations')->where('cash_account_id', $account->id)->select('id'));
        })->count();
        if ($openOutstanding > 0) {
            $blockers[] = ['code' => 'open_outstanding_items', 'count' => $openOutstanding, 'message' => 'Open reconciliation outstanding items must be resolved before closure.'];
        }
        if ($this->currentCustodian($account)) {
            $blockers[] = ['code' => 'active_custodian', 'message' => 'The active primary custodian assignment must be ended before closure.'];
        }

        return $blockers;
    }

    public function requestClosure(CashAccount $account, array $input, Company $company, Request $request): CashAccount
    {
        $this->scope($account, $company);
        $this->checkVersion($account, $input);
        if (! in_array($account->status, ['active', 'restricted', 'inactive'], true) || ! in_array($account->closure_status ?? 'none', ['none', 'cancelled'], true)) {
            throw new RegistryConflictException('This Cash Account cannot enter the closure workflow from its current status.');
        }
        $blockers = $this->closureBlockers($account, $company);
        if ($blockers !== []) {
            throw new RegistryConflictException('Cash Account closure is blocked until all account blockers are resolved.', ['blockers' => $blockers]);
        }
        $before = $this->safe($account);
        $account->update(['status' => 'pending_closure', 'status_reason' => $input['reason'], 'status_changed_at' => now(), 'status_changed_by' => $request->user()?->id, 'closure_status' => 'requested', 'closure_original_status' => $account->status, 'closure_reason' => $input['reason'], 'closure_effective_date' => $input['effective_date'] ?? now()->toDateString(), 'closure_blockers' => [], 'closure_requested_by' => $request->user()?->id, 'closure_requested_at' => now(), 'version' => $account->version + 1, 'updated_by' => $request->user()?->id]);
        $this->audit($request, 'EVT-CAS-023', $account, 'Cash Account closure requested', 'A Cash Account closure request was created.', $input['reason'], $before, $this->safe($account));
        CashAccountLifecycleEvent::dispatch('EVT-CAS-023', $company->id, $account->id, $account->status, $account->closure_status);

        return $account->fresh(['type', 'accountTitle', 'currency', 'branch', 'institution', 'capabilities', 'custodians']);
    }

    public function reviewClosure(CashAccount $account, array $input, Company $company, Request $request): CashAccount
    {
        $this->scope($account, $company);
        $this->checkVersion($account, $input);
        if ($account->status !== 'pending_closure' || $account->closure_status !== 'requested') {
            throw new RegistryConflictException('Only a requested Cash Account closure may enter review.');
        }
        $account->update(['closure_status' => 'under_review', 'closure_reviewed_by' => $request->user()?->id, 'closure_reviewed_at' => now(), 'version' => $account->version + 1, 'updated_by' => $request->user()?->id]);
        $this->audit($request, 'cash-account.closure.reviewed', $account, 'Cash Account closure under review', 'The Cash Account closure request entered review.', $input['reason'] ?? null);

        return $account->fresh(['type', 'accountTitle', 'currency', 'branch', 'institution', 'capabilities', 'custodians']);
    }

    public function approveClosure(CashAccount $account, array $input, Company $company, Request $request): CashAccount
    {
        $this->scope($account, $company);
        $this->checkVersion($account, $input);
        if ($account->status !== 'pending_closure' || $account->closure_status !== 'balance_resolution') {
            throw new RegistryConflictException('Only a Cash Account closure with completed balance resolution may be approved.');
        }
        if (! $account->attachments()->exists()) {
            throw new RegistryConflictException('Archive evidence is required before a Cash Account closure can be approved.', ['dependency' => 'archive_evidence']);
        }
        if ($account->closure_requested_by && (int) $account->closure_requested_by === (int) $request->user()?->id) {
            throw new RegistryConflictException('The user who requested closure cannot approve the same closure.', ['segregation' => true]);
        }
        $account->update(['closure_status' => 'approved', 'closure_approved_by' => $request->user()?->id, 'closure_approved_at' => now(), 'version' => $account->version + 1, 'updated_by' => $request->user()?->id]);
        $this->audit($request, 'cash-account.closure.approved', $account, 'Cash Account closure approved', 'The Cash Account closure request was approved.', $input['reason'] ?? null);

        return $account->fresh(['type', 'accountTitle', 'currency', 'branch', 'institution', 'capabilities', 'custodians']);
    }

    public function resolveClosure(CashAccount $account, array $input, Company $company, Request $request): CashAccount
    {
        $this->scope($account, $company);
        $this->checkVersion($account, $input);
        if ($account->status !== 'pending_closure' || $account->closure_status !== 'under_review') {
            throw new RegistryConflictException('Only a Cash Account closure under review may enter balance resolution.');
        }
        $blockers = $this->closureBlockers($account, $company);
        if ($blockers !== []) {
            throw new RegistryConflictException('Balance resolution is incomplete for this Cash Account.', ['blockers' => $blockers]);
        }
        $account->update(['closure_status' => 'balance_resolution', 'closure_blockers' => [], 'version' => $account->version + 1, 'updated_by' => $request->user()?->id]);
        $this->audit($request, 'cash-account.closure.balance-resolved', $account, 'Cash Account balance resolution completed', 'Cash Account closure blockers were re-evaluated and resolved.', $input['reason'] ?? null);

        return $account->fresh(['type', 'accountTitle', 'currency', 'branch', 'institution', 'capabilities', 'custodians']);
    }

    public function close(CashAccount $account, array $input, Company $company, Request $request): CashAccount
    {
        $this->scope($account, $company);
        $this->checkVersion($account, $input);
        if ($account->status !== 'pending_closure' || $account->closure_status !== 'approved') {
            throw new RegistryConflictException('Only an approved Cash Account closure may be closed.');
        }
        $blockers = $this->closureBlockers($account, $company);
        if ($blockers !== []) {
            throw new RegistryConflictException('Cash Account closure is blocked because the account changed after approval.', ['blockers' => $blockers, 'stale_approval' => true]);
        }
        if ($account->closure_approved_by && (int) $account->closure_approved_by === (int) $request->user()?->id) {
            throw new RegistryConflictException('The user who approved closure cannot execute the same closure.', ['segregation' => true]);
        }
        $before = $this->safe($account);
        $account->update(['status' => 'closed', 'closure_status' => 'closed', 'status_reason' => $input['reason'] ?? $account->closure_reason, 'status_changed_at' => now(), 'status_changed_by' => $request->user()?->id, 'closed_by' => $request->user()?->id, 'closed_at' => now(), 'version' => $account->version + 1, 'updated_by' => $request->user()?->id]);
        $this->audit($request, 'EVT-CAS-024', $account, 'Cash Account closed', 'The Cash Account was closed after governed approval and blocker re-evaluation.', $input['reason'] ?? $account->closure_reason, $before, $this->safe($account));
        CashAccountLifecycleEvent::dispatch('EVT-CAS-024', $company->id, $account->id, $account->status, $account->closure_status);

        return $account->fresh(['type', 'accountTitle', 'currency', 'branch', 'institution', 'capabilities', 'custodians']);
    }

    public function cancelClosure(CashAccount $account, array $input, Company $company, Request $request): CashAccount
    {
        $this->scope($account, $company);
        $this->checkVersion($account, $input);
        if ($account->status !== 'pending_closure' || ! in_array($account->closure_status, ['requested', 'under_review', 'balance_resolution', 'approved'], true)) {
            throw new RegistryConflictException('This Cash Account closure cannot be cancelled from its current status.');
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new RegistryConflictException('A reason is required to cancel a Cash Account closure.');
        }
        $original = $account->closure_original_status ?: 'inactive';
        $account->update(['status' => $original, 'status_reason' => $reason, 'status_changed_at' => now(), 'status_changed_by' => $request->user()?->id, 'closure_status' => 'cancelled', 'closure_cancelled_by' => $request->user()?->id, 'closure_cancelled_at' => now(), 'version' => $account->version + 1, 'updated_by' => $request->user()?->id]);
        $this->audit($request, 'cash-account.closure.cancelled', $account, 'Cash Account closure cancelled', 'The Cash Account closure was cancelled and the prior lifecycle status was restored.', $reason);

        return $account->fresh(['type', 'accountTitle', 'currency', 'branch', 'institution', 'capabilities', 'custodians']);
    }

    private function checkVersion(CashAccount $account, array $input): void
    {
        if ((int) ($input['version'] ?? 0) !== (int) $account->version) {
            throw new RegistryConflictException('This Cash Account was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
    }

    private function addCountBlocker(array &$blockers, string $code, string $table, string $accountId, array $statuses, string $message, ?array $accountColumns = null, ?array $excludeStatuses = null): void
    {
        $query = DB::table($table)->whereIn('status', $statuses);
        if ($table === 'cash_transfer_documents') {
            $query->where(function ($builder) use ($accountId, $accountColumns) {
                foreach ($accountColumns ?? [] as $column) {
                    $builder->orWhere($column, $accountId);
                }
            });
        } else {
            $query->where('cash_account_id', $accountId);
        }
        if ($excludeStatuses) {
            $query->whereNotIn('status', $excludeStatuses);
        }
        $count = $query->count();
        if ($count > 0) {
            $blockers[] = ['code' => $code, 'count' => $count, 'message' => $message];
        }
    }

    public function updateCapabilities(CashAccount $account, array $input, Company $company, Request $request): CashAccount
    {
        $this->scope($account, $company);
        if (in_array($account->status, ['pending_closure', 'closed'], true)) {
            throw new RegistryConflictException('Cash Account capabilities cannot be changed while closure is pending or after the account is closed.');
        }
        if ((int) ($input['version'] ?? 0) !== (int) $account->version) {
            throw new RegistryConflictException('This Cash Account was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $capabilities = $this->capabilities($input['capabilities'] ?? [], $account->type);
        DB::transaction(function () use ($account, $capabilities, $request) {
            foreach (CashAccountCapabilityCatalog::ALL as $capability) {
                CashAccountCapability::updateOrCreate(['cash_account_id' => $account->id, 'capability' => $capability], ['enabled' => in_array($capability, $capabilities, true), 'version' => 1, 'updated_by' => $request->user()?->id]);
            }
            CashAccount::whereKey($account->id)->where('version', $account->version)->update(['version' => $account->version + 1, 'updated_by' => $request->user()?->id]);
        });
        $account->refresh()->load('capabilities');
        $this->audit($request, 'cash-account.capabilities.changed', $account, 'Cash Account capabilities changed', 'The enabled Cash Account capabilities were changed.');

        return $account;
    }

    public function transition(CashAccount $account, string $status, array $input, Company $company, Request $request): CashAccount
    {
        $this->scope($account, $company);
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new RegistryConflictException('A reason is required for a Cash Account lifecycle change.');
        }
        $allowed = ['activate' => ['draft', 'inactive'], 'restrict' => ['active'], 'unrestrict' => ['restricted'], 'deactivate' => ['active', 'restricted', 'draft'], 'reactivate' => ['inactive']];
        $action = $status === 'active' && $account->status === 'restricted' ? 'unrestrict' : $status;
        if (! isset($allowed[$action]) || ! in_array($account->status, $allowed[$action], true)) {
            throw new RegistryConflictException('This Cash Account cannot make that lifecycle transition from its current status.');
        }
        if (in_array($action, ['activate', 'reactivate'], true)) {
            $this->validateActivation($account, $company);
        }
        $next = match ($action) {
            'activate', 'reactivate', 'unrestrict' => 'active', 'restrict' => 'restricted', default => 'inactive'
        };
        $before = $this->safe($account);
        $updates = ['status' => $next, 'status_reason' => $reason, 'status_changed_at' => now(), 'status_changed_by' => $request->user()?->id, 'updated_by' => $request->user()?->id, 'version' => $account->version + 1];
        if ($action === 'activate') {
            $updates += ['activated_by' => $request->user()?->id, 'activated_at' => now()];
        }
        if ($action === 'reactivate') {
            $updates += ['reactivated_by' => $request->user()?->id, 'reactivated_at' => now()];
        }
        if ($action === 'restrict') {
            $updates += ['restricted_by' => $request->user()?->id, 'restricted_at' => now(), 'restricted_capabilities' => $input['restricted_capabilities'] ?? null];
        }
        if ($action === 'deactivate') {
            $updates += ['deactivated_by' => $request->user()?->id, 'deactivated_at' => now()];
        }
        if (CashAccount::whereKey($account->id)->where('version', $account->version)->update($updates) !== 1) {
            throw new RegistryConflictException('This Cash Account was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $account->refresh();
        $this->audit($request, 'EVT-CAS-00'.($action === 'activate' ? '3' : '4'), $account, 'Cash Account status changed', "Cash Account status changed to {$next}.", $reason, $before, $this->safe($account));

        return $account->load(['type', 'accountTitle', 'currency', 'branch', 'institution', 'capabilities', 'custodians']);
    }

    public function assignCustodian(CashAccount $account, array $input, Company $company, Request $request): CashAccountCustodian
    {
        $this->scope($account, $company);
        if (in_array($account->status, ['pending_closure', 'closed'], true)) {
            throw new RegistryConflictException('Custodian assignments cannot be added while closure is pending or after the account is closed.');
        }
        $user = User::whereKey($input['user_id'] ?? 0)->where('status', 'active')->first();
        if (! $user || ! $user->companies()->whereKey($company->id)->wherePivot('status', 'active')->exists()) {
            throw new RegistryConflictException('The custodian must be an active user in the current company.');
        }
        $from = $input['effective_from'] ?? now()->toDateString();
        $to = $input['effective_to'] ?? null;
        if ($to !== null && $to < $from) {
            throw new RegistryConflictException('Custodian effective dates are invalid.');
        }
        if (! empty($input['is_primary']) && $this->overlappingPrimary($account, $from, $to)) {
            throw new RegistryConflictException('Another primary custodian overlaps the requested effective period.');
        }
        $assignment = CashAccountCustodian::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id, 'user_id' => $user->id, 'responsibility_type' => $input['responsibility_type'] ?? 'custodian', 'effective_from' => $from, 'effective_to' => $to, 'is_primary' => (bool) ($input['is_primary'] ?? false), 'notes' => $input['notes'] ?? null, 'reason' => $input['reason'] ?? null, 'assigned_by' => $request->user()?->id, 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $this->audit($request, 'cash-account.custodian.assigned', $account, 'Custodian assigned', 'A Cash Account custodian was assigned.');

        return $assignment->load('user');
    }

    public function endCustodian(CashAccountCustodian $assignment, array $input, Company $company, Request $request): CashAccountCustodian
    {
        if ((int) ($assignment->company_id) !== (int) $company->id) {
            throw new RegistryConflictException('The custodian assignment is outside the current company scope.');
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new RegistryConflictException('A reason is required to end a custodian assignment.');
        }
        if ($assignment->status !== 'active') {
            throw new RegistryConflictException('This custodian assignment has already ended.');
        }
        $assignment->update(['status' => 'ended', 'effective_to' => $input['effective_to'] ?? now()->toDateString(), 'ended_by' => $request->user()?->id, 'ended_at' => now(), 'reason' => $reason, 'version' => $assignment->version + 1]);
        $this->audit($request, 'cash-account.custodian.ended', $assignment->account, 'Custodian assignment ended', 'A Cash Account custodian assignment was ended.', $reason);

        return $assignment->load('user');
    }

    public function validateActivation(CashAccount $account, Company $company): void
    {
        $account->loadMissing(['type', 'accountTitle', 'currency', 'branch', 'capabilities']);
        $this->validateReferences(['account_title_id' => $account->account_title_id, 'currency_id' => $account->currency_id, 'branch_id' => $account->branch_id], $company, $account->type);
        $enabled = $account->capabilities->where('enabled', true)->pluck('capability')->all();
        $this->capabilities($enabled, $account->type);
        if ($account->type->requires_custodian && ! $this->currentCustodian($account)) {
            throw new RegistryConflictException('A physical Cash Account requires a current primary custodian before activation.', ['dependency' => 'custodian']);
        }
    }

    public function currentCustodian(CashAccount $account): ?CashAccountCustodian
    {
        $today = now()->toDateString();

        return $account->custodians()->where('status', 'active')->where('is_primary', true)->whereDate('effective_from', '<=', $today)->where(function ($query) use ($today) {
            $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
        })->first();
    }

    public function type(?string $id): CashAccountType
    {
        return CashAccountType::whereKey($id)->where('status', 'active')->firstOrFail();
    }

    public function scope(CashAccount $account, Company $company): void
    {
        if ((int) $account->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The Cash Account is outside the current company scope.');
        }
    }

    public function safe(CashAccount $account): array
    {
        return ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'status' => $account->status, 'version' => $account->version, 'closure_status' => $account->closure_status ?? 'none', 'masked_account_identifier' => $account->masked_account_identifier];
    }

    private function validateReferences(array $input, Company $company, CashAccountType $type): void
    {
        $today = now()->toDateString();
        $accountTitle = AccountTitle::where('company_id', $company->id)->whereKey($input['account_title_id'] ?? '')->where('status', 'active')->where(function ($query) use ($today) {
            $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today);
        })->where(function ($query) use ($today) {
            $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
        })->first();
        if (! $accountTitle || $accountTitle->classification !== 'asset') {
            throw new RegistryConflictException('Cash Accounts must map to an active same-company Asset Account Title.');
        }
        $currency = ReferenceCurrency::where('company_id', $company->id)->whereKey($input['currency_id'] ?? '')->where('status', 'active')->where(function ($query) use ($today) {
            $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today);
        })->where(function ($query) use ($today) {
            $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
        })->exists();
        if (! $currency) {
            throw new RegistryConflictException('The Currency must be active, effective, and belong to the current company.');
        }
        if (! empty($input['branch_id']) && ! Branch::where('company_id', $company->id)->whereKey($input['branch_id'])->where('status', 'active')->exists()) {
            throw new RegistryConflictException('The Branch must be active and belong to the current company.');
        }
        if (! $type->id) {
            throw new RegistryConflictException('A valid Cash Account Type is required.');
        }
    }

    private function capabilities(?array $requested, CashAccountType $type): array
    {
        $capabilities = $requested ?? $type->default_capabilities;
        if (! is_array($capabilities) || count(array_diff($capabilities, CashAccountCapabilityCatalog::ALL)) > 0 || count(array_diff($capabilities, $type->allowed_capabilities)) > 0) {
            throw new RegistryConflictException('The requested Cash Account capabilities are not valid for this Account Type.', ['capabilities' => ['Some capabilities are not supported by the selected Account Type.']]);
        }

        return array_values(array_unique($capabilities));
    }

    private function storeCapabilities(CashAccount $account, array $capabilities, ?int $actor): void
    {
        foreach (CashAccountCapabilityCatalog::ALL as $capability) {
            CashAccountCapability::create(['id' => (string) Str::uuid(), 'cash_account_id' => $account->id, 'capability' => $capability, 'enabled' => in_array($capability, $capabilities, true), 'version' => 1, 'updated_by' => $actor]);
        }
    }

    private function institution(?array $input, Company $company, Request $request): ?CashAccountInstitution
    {
        if (! $input) {
            return null;
        }
        if (empty($input['name']) || empty($input['provider_type'])) {
            throw new RegistryConflictException('Institution/provider name and type are required together.');
        }

        return CashAccountInstitution::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'provider_type' => $input['provider_type'], 'name' => trim($input['name']), 'branch_name' => $input['branch_name'] ?? null, 'routing_reference' => $input['routing_reference'] ?? null, 'statement_format' => $input['statement_format'] ?? null, 'effective_from' => $input['effective_from'] ?? now()->toDateString(), 'effective_to' => $input['effective_to'] ?? null, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function overlappingPrimary(CashAccount $account, string $from, ?string $to): bool
    {
        return $account->custodians()->where('status', 'active')->where('is_primary', true)->whereDate('effective_from', '<=', $to ?? '9999-12-31')->where(function ($query) use ($from) {
            $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from);
        })->exists();
    }

    private function mask(?string $identifier): ?string
    {
        if (! $identifier) {
            return null;
        }

        return '••••'.substr($identifier, -4);
    }

    private function audit(Request $request, string $event, Model $record, string $title, string $description, ?string $reason = null, array $before = [], array $after = []): void
    {
        $this->audit->record($request, $event, $record, $request->attributes->get('company')?->id, $before, $after ?: $this->safe($record instanceof CashAccount ? $record : $record), $reason, $title, $description);
    }
}
