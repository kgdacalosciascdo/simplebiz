<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccounts\EvidenceRequest;
use App\Http\Resources\CashAccounts\ReconciliationAdjustmentResource;
use App\Models\ReconciliationAdjustment;
use App\Services\ReconciliationAdjustmentService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class ReconciliationAdjustmentController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly ReconciliationAdjustmentService $service, private readonly IdempotencyService $idempotency) {}

    public function show(string $id)
    {
        return ApiResponse::success(new ReconciliationAdjustmentResource($this->service->load($this->adjustment($id))));
    }

    public function store(Request $request)
    {
        $input = $request->validate(['reconciliation_id' => ['required', 'uuid'], 'outstanding_item_id' => ['required', 'uuid'], 'direction' => ['required', 'in:increase,decrease'], 'amount' => ['required', 'numeric', 'gt:0'], 'offset_account_title_id' => ['required', 'uuid'], 'reason_code_id' => ['required', 'uuid'], 'business_date' => ['nullable', 'date'], 'explanation' => ['required', 'string', 'max:2000']]);

        return $this->idempotency->run($request, 'cash-accounts.reconciliation-adjustments.create', $this->context->id(), fn () => ApiResponse::success(new ReconciliationAdjustmentResource($this->service->create($input, $this->context->get(), $request)), 201));
    }

    public function uploadEvidence(EvidenceRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.reconciliation-adjustments.evidence', $this->context->id(), fn () => ApiResponse::success(new ReconciliationAdjustmentResource($this->service->uploadEvidence($this->adjustment($id), $request->file('file'), $this->context->get(), $request))));
    }

    public function action(Request $request, string $id, string $action)
    {
        $adjustment = $this->adjustment($id);
        $run = fn () => match ($action) {
            'submit' => $this->service->submit($adjustment, $this->context->get(), $request), 'approve' => $this->service->approve($adjustment, $this->context->get(), $request), 'post' => $this->service->post($adjustment, $this->context->get(), $request), 'reverse' => $this->service->reverse($adjustment, $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $this->context->get(), $request), default => abort(404),
        };

        return $this->idempotency->run($request, 'cash-accounts.reconciliation-adjustments.'.$action, $this->context->id(), fn () => ApiResponse::success(new ReconciliationAdjustmentResource($run())));
    }

    private function adjustment(string $id): ReconciliationAdjustment
    {
        return ReconciliationAdjustment::where('company_id', $this->context->id())->whereKey($id)->with(['reconciliation', 'outstandingItem', 'movementDocument', 'attachments'])->firstOrFail();
    }
}
