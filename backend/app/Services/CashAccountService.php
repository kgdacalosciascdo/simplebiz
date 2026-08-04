<?php

namespace App\Services;

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

    public function updateCapabilities(CashAccount $account, array $input, Company $company, Request $request): CashAccount
    {
        $this->scope($account, $company);
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
        return ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'status' => $account->status, 'version' => $account->version, 'masked_account_identifier' => $account->masked_account_identifier];
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
