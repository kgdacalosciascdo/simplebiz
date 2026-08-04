<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CashAccounts\ReconciliationResource;
use App\Models\Reconciliation;
use App\Models\ReconciliationMatch;
use App\Services\ReconciliationService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly ReconciliationService $service, private readonly IdempotencyService $idempotency) {}

    public function index(Request $request)
    {
        $query = Reconciliation::where('company_id', $this->context->id())->with('account.currency')->orderByDesc('created_at');
        if ($request->filled('cash_account_id')) {
            $query->where('cash_account_id', $request->string('cash_account_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return ReconciliationResource::collection($query->paginate(min((int) $request->integer('per_page', 20), 100)));
    }

    public function show(string $id)
    {
        return ApiResponse::success(new ReconciliationResource($this->reconciliation($id)));
    }

    public function store(Request $request)
    {
        $input = $request->validate(['statement_import_batch_id' => ['required', 'uuid'], 'cash_account_id' => ['nullable', 'uuid']]);

        return $this->idempotency->run($request, 'cash-accounts.reconciliations.create', $this->context->id(), fn () => ApiResponse::success(new ReconciliationResource($this->service->create($input, $this->context->get(), $request)), 201));
    }

    public function refresh(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.reconciliations.update', $this->context->id(), fn () => ApiResponse::success(new ReconciliationResource($this->service->refresh($this->reconciliation($id), $this->context->get(), $request))));
    }

    public function candidates(string $id)
    {
        return ApiResponse::success($this->service->candidates($this->reconciliation($id), $this->context->get()));
    }

    public function match(Request $request, string $id)
    {
        $input = $request->validate(['statement_line_ids' => ['required', 'array', 'min:1'], 'statement_line_ids.*' => ['uuid'], 'cash_movement_ids' => ['required', 'array', 'min:1'], 'cash_movement_ids.*' => ['uuid'], 'method' => ['nullable', 'in:exact,tolerance,manual,split,combined'], 'tolerance_amount' => ['nullable', 'numeric', 'min:0'], 'reason' => ['nullable', 'string', 'max:1000']]);

        return $this->idempotency->run($request, 'cash-accounts.reconciliation.matches.create', $this->context->id(), fn () => ApiResponse::success($this->service->match($this->reconciliation($id), $input, $this->context->get(), $request), 201));
    }

    public function unmatch(Request $request, string $matchId)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $match = ReconciliationMatch::where('company_id', $this->context->id())->whereKey($matchId)->firstOrFail();

        return $this->idempotency->run($request, 'cash-accounts.reconciliation.matches.unmatch', $this->context->id(), fn () => ApiResponse::success($this->service->unmatch($match, $input['reason'], $this->context->get(), $request)));
    }

    public function outstanding(Request $request, string $id)
    {
        $input = $request->validate(['source_type' => ['required', 'in:statement_line,cash_movement'], 'source_id' => ['required', 'uuid'], 'classification' => ['required', 'string', 'max:48'], 'owner_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string', 'max:1000']]);

        return $this->idempotency->run($request, 'cash-accounts.reconciliations.outstanding', $this->context->id(), fn () => ApiResponse::success($this->service->outstanding($this->reconciliation($id), $input, $this->context->get(), $request), 201));
    }

    public function action(Request $request, string $id, string $action)
    {
        $input = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->idempotency->run($request, 'cash-accounts.reconciliations.'.$action, $this->context->id(), fn () => ApiResponse::success(new ReconciliationResource($this->service->transition($this->reconciliation($id), $action, $input['reason'] ?? null, $this->context->get(), $request))));
    }

    public function history(string $id)
    {
        return ApiResponse::success($this->reconciliation($id)->history()->orderBy('created_at')->get());
    }

    private function reconciliation(string $id): Reconciliation
    {
        return Reconciliation::where('company_id', $this->context->id())->whereKey($id)->with(['account.currency', 'batch.account', 'batch.lines', 'matches.allocations', 'outstandingItems', 'history', 'completions'])->firstOrFail();
    }
}
