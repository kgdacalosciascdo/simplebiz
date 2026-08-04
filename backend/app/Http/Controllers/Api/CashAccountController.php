<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccounts\CapabilityRequest;
use App\Http\Requests\CashAccounts\CustodianRequest;
use App\Http\Requests\CashAccounts\EndCustodianRequest;
use App\Http\Requests\CashAccounts\StoreCashAccountRequest;
use App\Http\Requests\CashAccounts\TransitionCashAccountRequest;
use App\Http\Requests\CashAccounts\UpdateCashAccountRequest;
use App\Http\Resources\CashAccounts\CashAccountResource;
use App\Http\Resources\CashAccounts\CashAccountTypeResource;
use App\Http\Resources\CashAccounts\CashMovementResource;
use App\Models\AccountTitle;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashAccountCustodian;
use App\Models\CashAccountType;
use App\Models\OpeningBalance;
use App\Models\ReasonCode;
use App\Models\ReferenceCurrency;
use App\Services\CashAccountService;
use App\Services\CashPositionService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class CashAccountController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly CashAccountService $service, private readonly CashPositionService $positions, private readonly IdempotencyService $idempotency) {}

    public function types()
    {
        return ApiResponse::success(CashAccountTypeResource::collection(CashAccountType::where('status', 'active')->orderBy('name')->get()));
    }

    public function lookups()
    {
        $company = $this->context->get();

        return ApiResponse::success(['types' => CashAccountTypeResource::collection(CashAccountType::where('status', 'active')->orderBy('name')->get())->resolve(), 'account_titles' => AccountTitle::where('company_id', $company->id)->where('classification', 'asset')->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'classification']), 'currencies' => ReferenceCurrency::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'symbol', 'decimal_precision']), 'branches' => Branch::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']), 'custodians' => $company->users()->wherePivot('status', 'active')->where('users.status', 'active')->orderBy('users.name')->get(['users.id', 'users.name', 'users.email']), 'opening_reason_codes' => ReasonCode::where('company_id', $company->id)->where('domain', 'OPENING_BALANCE')->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'domain'])]);
    }

    public function index(Request $request)
    {
        $company = $this->context->get();
        $query = CashAccount::where('company_id', $company->id)->with(['type', 'accountTitle', 'currency', 'branch', 'capabilities', 'custodians']);
        if ($request->filled('q')) {
            $query->where(fn ($builder) => $builder->where('code', 'like', '%'.$request->string('q').'%')->orWhere('name', 'like', '%'.$request->string('q').'%'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('cash_account_type_id')) {
            $query->where('cash_account_type_id', $request->string('cash_account_type_id'));
        }
        if ($request->filled('currency_id')) {
            $query->where('currency_id', $request->string('currency_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->string('branch_id'));
        }
        $paginator = $query->orderBy($request->string('sort', 'name'), $request->string('direction', 'asc') === 'desc' ? 'desc' : 'asc')->paginate(min((int) $request->integer('per_page', 20), 100));
        $paginator->getCollection()->transform(function (CashAccount $account) {
            $account->setAttribute('position', $this->positions->forAccount($account));

            return $account;
        });

        return CashAccountResource::collection($paginator);
    }

    public function summary()
    {
        $company = $this->context->get();
        $accounts = CashAccount::where('company_id', $company->id)->get(['id', 'status', 'cash_account_type_id']);

        return ApiResponse::success(['account_count' => $accounts->count(), 'active_account_count' => $accounts->where('status', 'active')->count(), 'restricted_account_count' => $accounts->where('status', 'restricted')->count(), 'draft_account_count' => $accounts->where('status', 'draft')->count(), 'position_by_currency' => $this->positions->grouped($company->id), 'as_of' => now()->toIso8601String()]);
    }

    public function needsAttention()
    {
        $company = $this->context->get();
        $drafts = CashAccount::where('company_id', $company->id)->where('status', 'draft')->get(['id', 'code', 'name', 'status']);
        $physicalWithoutCustodian = CashAccount::where('cash_accounts.company_id', $company->id)->where('cash_accounts.status', 'active')->whereHas('type', fn ($query) => $query->where('requires_custodian', true))->whereDoesntHave('custodians', fn ($query) => $query->where('status', 'active')->where('is_primary', true)->whereDate('effective_from', '<=', now()->toDateString())->where(fn ($inner) => $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString())))->get(['id', 'code', 'name', 'status']);
        $pendingOpening = OpeningBalance::where('company_id', $company->id)->whereIn('status', ['submitted', 'approved'])->with('account')->get()->map(fn ($opening) => ['id' => $opening->id, 'status' => $opening->status, 'account' => $opening->account ? ['id' => $opening->account->id, 'code' => $opening->account->code, 'name' => $opening->account->name] : null]);

        return ApiResponse::success(['draft_accounts' => $drafts, 'physical_accounts_missing_custodian' => $physicalWithoutCustodian, 'opening_balances_pending' => $pendingOpening, 'configuration' => ['opening_balance_offset_account_title_id' => $company->opening_balance_offset_account_title_id, 'ready_for_posting' => (bool) $company->opening_balance_offset_account_title_id]]);
    }

    public function store(StoreCashAccountRequest $request)
    {
        return $this->idempotency->run($request, 'cash-accounts.account.create', $this->context->id(), function () use ($request) {
            $account = $this->service->create($request->validated(), $this->context->get(), $request);

            return ApiResponse::success(new CashAccountResource($account), 201);
        });
    }

    public function show(string $id)
    {
        $account = $this->account($id)->load(['type', 'accountTitle', 'currency', 'branch', 'institution', 'capabilities', 'custodians.user', 'openingBalances.currency', 'openingBalances.reasonCode', 'openingBalances.attachments', 'openingBalances.movement']);
        $account->setAttribute('position', $this->positions->forAccount($account));

        return ApiResponse::success(new CashAccountResource($account));
    }

    public function update(UpdateCashAccountRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.account.update', $this->context->id(), function () use ($request, $id) {
            $account = $this->service->update($this->account($id), $request->validated(), $this->context->get(), $request);

            return ApiResponse::success(new CashAccountResource($account));
        });
    }

    public function activate(TransitionCashAccountRequest $request, string $id)
    {
        return $this->transition($request, $id, 'activate', 'cash-accounts.account.activate');
    }

    public function restrict(TransitionCashAccountRequest $request, string $id)
    {
        return $this->transition($request, $id, 'restrict', 'cash-accounts.account.restrict');
    }

    public function unrestrict(TransitionCashAccountRequest $request, string $id)
    {
        return $this->transition($request, $id, 'unrestrict', 'cash-accounts.account.activate');
    }

    public function deactivate(TransitionCashAccountRequest $request, string $id)
    {
        return $this->transition($request, $id, 'deactivate', 'cash-accounts.account.deactivate');
    }

    public function reactivate(TransitionCashAccountRequest $request, string $id)
    {
        return $this->transition($request, $id, 'reactivate', 'cash-accounts.account.reactivate');
    }

    public function capabilities(CapabilityRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.capabilities.update', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashAccountResource($this->service->updateCapabilities($this->account($id), $request->validated(), $this->context->get(), $request)));
        });
    }

    public function custodians(string $id)
    {
        return ApiResponse::success($this->account($id)->custodians()->with('user')->orderByDesc('is_primary')->orderByDesc('effective_from')->get()->map(fn ($assignment) => ['id' => $assignment->id, 'user_id' => $assignment->user_id, 'user_name' => $assignment->user?->name, 'user_email' => $assignment->user?->email, 'responsibility_type' => $assignment->responsibility_type, 'effective_from' => $assignment->effective_from?->toDateString(), 'effective_to' => $assignment->effective_to?->toDateString(), 'is_primary' => $assignment->is_primary, 'status' => $assignment->status, 'version' => $assignment->version]));
    }

    public function assignCustodian(CustodianRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.custodian.assign', $this->context->id(), function () use ($request, $id) {
            $assignment = $this->service->assignCustodian($this->account($id), $request->validated(), $this->context->get(), $request);

            return ApiResponse::success(['id' => $assignment->id, 'user_id' => $assignment->user_id, 'status' => $assignment->status, 'effective_from' => $assignment->effective_from?->toDateString(), 'effective_to' => $assignment->effective_to?->toDateString(), 'is_primary' => $assignment->is_primary], 201);
        });
    }

    public function endCustodian(EndCustodianRequest $request, string $id, string $custodianId)
    {
        $assignment = CashAccountCustodian::where('cash_account_id', $id)->whereKey($custodianId)->firstOrFail();

        return $this->idempotency->run($request, 'cash-accounts.custodian.end', $this->context->id(), function () use ($request, $assignment) {
            $ended = $this->service->endCustodian($assignment, $request->validated(), $this->context->get(), $request);

            return ApiResponse::success(['id' => $ended->id, 'status' => $ended->status, 'effective_to' => $ended->effective_to?->toDateString()]);
        });
    }

    public function balance(string $id)
    {
        return ApiResponse::success($this->positions->forAccount($this->account($id)));
    }

    public function movements(Request $request, string $id)
    {
        $movements = $this->account($id)->movements()->orderByDesc('business_date')->orderByDesc('posted_at')->paginate(min((int) $request->integer('per_page', 20), 100));

        return CashMovementResource::collection($movements);
    }

    public function history(string $id)
    {
        $account = $this->account($id);

        return ApiResponse::success(AuditLog::where('company_id', $this->context->id())->where('entity_id', $account->id)->orderByDesc('created_at')->paginate(50));
    }

    private function transition(TransitionCashAccountRequest $request, string $id, string $status, string $operation)
    {
        return $this->idempotency->run($request, $operation, $this->context->id(), function () use ($request, $id, $status) {
            $account = $this->service->transition($this->account($id), $status, $request->validated(), $this->context->get(), $request);

            return ApiResponse::success(new CashAccountResource($account));
        });
    }

    private function account(string $id): CashAccount
    {
        return CashAccount::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }
}
