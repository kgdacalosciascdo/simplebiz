<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\CashAccount;
use App\Models\CashAccountCustodian;
use App\Models\CashCount;
use App\Models\CashCustodianHandover;
use App\Models\Company;
use App\Models\User;
use App\Support\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CashCustodianHandoverService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers) {}

    public function create(array $input, Company $company, Request $request): CashCustodianHandover
    {
        $account = CashAccount::where('company_id', $company->id)->whereKey($input['cash_account_id'])->with('currency')->firstOrFail();
        $current = $this->current($account);
        if (! $current) {
            throw new RegistryConflictException('A current primary custodian is required before handover.');
        }
        $incoming = $this->user($input['incoming_custodian_id'], $company);
        if ((int) $incoming->id === (int) $current->user_id) {
            throw new RegistryConflictException('The incoming custodian must differ from the outgoing custodian.');
        }
        $count = CashCount::where('company_id', $company->id)->whereKey($input['cash_count_id'])->with('variance')->firstOrFail();
        if ((string) $count->cash_account_id !== (string) $account->id || ! in_array($count->status, ['approved', 'adjusted', 'closed'], true)) {
            throw new RegistryConflictException('A completed approved Cash Count for the same account is required for handover.');
        }
        if ((string) $count->current_attempt_id !== (string) $input['accepted_attempt_id']) {
            throw new RegistryConflictException('The handover must bind to the accepted current count attempt.');
        }
        if ($count->variance && ! in_array($count->variance->status, ['balanced', 'waived', 'adjustment_posted'], true)) {
            throw new RegistryConflictException('Unresolved variance blocks custodian handover.');
        }
        $handover = CashCustodianHandover::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'handover_number' => DB::transaction(fn () => $this->numbers->next($company->id, 'custodian_handover')), 'cash_account_id' => $account->id, 'cash_count_id' => $count->id, 'accepted_attempt_id' => $input['accepted_attempt_id'], 'outgoing_custodian_id' => $current->user_id, 'incoming_custodian_id' => $incoming->id, 'witness_id' => $input['witness_id'] ?? null, 'status' => 'draft', 'handover_date' => $input['handover_date'], 'reason' => $input['reason'], 'prepared_by' => $request->user()?->id, 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
        $this->audit->record($request, 'cash-handover.drafted', $handover, $company->id, [], $this->safe($handover), null, 'Custodian handover drafted', 'Custody remains unchanged until handover completion.');

        return $this->load($handover);
    }

    public function confirm(CashCustodianHandover $handover, string $type, string $comments, Company $company, Request $request): CashCustodianHandover
    {
        $this->scope($handover, $company);
        if (! in_array($handover->status, ['draft', 'confirmed'], true)) {
            throw new RegistryConflictException('Only draft handovers may receive confirmation.');
        }
        $userId = (int) $request->user()?->id;
        $updates = match ($type) {
            'outgoing' => $userId === (int) $handover->outgoing_custodian_id ? ['outgoing_confirmed_by' => $userId, 'outgoing_confirmed_at' => now(), 'outgoing_comments' => $comments] : throw new RegistryConflictException('Only the outgoing custodian may confirm departure.'),
            'incoming' => $userId === (int) $handover->incoming_custodian_id ? ['incoming_confirmed_by' => $userId, 'incoming_confirmed_at' => now(), 'incoming_comments' => $comments] : throw new RegistryConflictException('Only the incoming custodian may confirm acceptance.'),
            default => throw new RegistryConflictException('Unsupported handover confirmation type.'),
        };
        $handover->update(array_merge($updates, ['status' => $handover->outgoing_confirmed_by || $type === 'outgoing' ? ($handover->incoming_confirmed_by || $type === 'incoming' ? 'confirmed' : 'draft') : 'draft', 'version' => $handover->version + 1]));
        $this->audit->record($request, 'cash-handover.'.$type.'.confirmed', $handover, $company->id, [], $this->safe($handover), null, 'Custodian handover confirmed', 'A custody party confirmed the governed handover record.');

        return $this->load($handover->refresh());
    }

    public function approve(CashCustodianHandover $handover, Company $company, Request $request): CashCustodianHandover
    {
        $this->scope($handover, $company);
        if ($handover->status !== 'confirmed') {
            throw new RegistryConflictException('Outgoing and incoming confirmations are required before approval.');
        }
        if (in_array((int) $request->user()?->id, [(int) $handover->outgoing_custodian_id, (int) $handover->incoming_custodian_id], true)) {
            throw new RegistryConflictException('A handover participant cannot approve the same handover.', ['segregation' => true]);
        }
        $handover->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $handover->version + 1]);

        return $this->load($handover->refresh());
    }

    public function complete(CashCustodianHandover $handover, Company $company, Request $request): CashCustodianHandover
    {
        $this->scope($handover, $company);
        if ($handover->status !== 'approved') {
            throw new RegistryConflictException('Only approved handovers may be completed.');
        }
        $result = DB::transaction(function () use ($handover, $company, $request) {
            $locked = CashCustodianHandover::whereKey($handover->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $account = CashAccount::whereKey($locked->cash_account_id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $current = $this->current($account);
            if (! $current || (int) $current->user_id !== (int) $locked->outgoing_custodian_id) {
                throw new RegistryConflictException('The current custodian changed before completion.', ['version_conflict' => true]);
            }
            $date = CarbonImmutable::parse($locked->handover_date);
            $current->update(['status' => 'ended', 'effective_to' => $date->subDay()->toDateString(), 'ended_by' => $request->user()?->id, 'ended_at' => now(), 'version' => $current->version + 1]);
            $new = CashAccountCustodian::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id, 'user_id' => $locked->incoming_custodian_id, 'responsibility_type' => 'custodian', 'effective_from' => $date->toDateString(), 'is_primary' => true, 'status' => 'active', 'assigned_by' => $request->user()?->id, 'reason' => $locked->reason, 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $locked->update(['status' => 'completed', 'completed_by' => $request->user()?->id, 'completed_at' => now(), 'version' => $locked->version + 1]);

            return $locked->refresh();
        });
        $this->audit->record($request, 'cash-handover.completed', $result, $company->id, [], $this->safe($result), null, 'Custodian handover completed', 'Outgoing custody ended and incoming custody became effective atomically.');

        return $this->load($result);
    }

    public function cancel(CashCustodianHandover $handover, string $reason, Company $company, Request $request): CashCustodianHandover
    {
        $this->scope($handover, $company);
        if (in_array($handover->status, ['completed', 'cancelled'], true) || trim($reason) === '') {
            throw new RegistryConflictException('This handover cannot be cancelled without a valid reason.');
        }
        $handover->update(['status' => 'cancelled', 'reason' => $handover->reason.' | Cancellation: '.$reason, 'version' => $handover->version + 1]);

        return $this->load($handover->refresh());
    }

    public function load(CashCustodianHandover $handover): CashCustodianHandover
    {
        return $handover->load(['account.currency', 'count.variance', 'attempt', 'attachments']);
    }

    private function current(CashAccount $account): ?CashAccountCustodian
    {
        return $account->custodians()->where('status', 'active')->where('is_primary', true)->whereDate('effective_from', '<=', now()->toDateString())->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))->first();
    }

    private function user(int $id, Company $company): User
    {
        $user = User::whereKey($id)->where('status', 'active')->whereHas('companies', fn ($query) => $query->whereKey($company->id)->where('company_user.status', 'active'))->first();
        if (! $user) {
            throw new RegistryConflictException('The selected incoming custodian must be active in the current company.');
        }

        return $user;
    }

    private function scope(CashCustodianHandover $handover, Company $company): void
    {
        if ((int) $handover->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The custodian handover is outside the current company scope.');
        }
    }

    private function safe(CashCustodianHandover $handover): array
    {
        return $handover->toArray();
    }
}
