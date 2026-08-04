<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccounts\EvidenceRequest;
use App\Http\Requests\CashAccounts\ReasonRequest;
use App\Http\Requests\CashAccounts\StoreCashHandoverRequest;
use App\Http\Resources\CashAccounts\AttachmentResource;
use App\Http\Resources\CashAccounts\CashHandoverResource;
use App\Models\CashCustodianHandover;
use App\Services\AttachmentService;
use App\Services\CashCustodianHandoverService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class CashCustodianHandoverController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly CashCustodianHandoverService $service, private readonly AttachmentService $attachments, private readonly IdempotencyService $idempotency) {}

    public function index(Request $request)
    {
        $query = CashCustodianHandover::where('company_id', $this->context->id())->with(['account', 'count', 'attachments'])->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')))->when($request->filled('cash_account_id'), fn ($builder) => $builder->where('cash_account_id', $request->string('cash_account_id')));

        return CashHandoverResource::collection($query->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 50), 100)));
    }

    public function show(string $id)
    {
        return ApiResponse::success(new CashHandoverResource($this->handover($id)));
    }

    public function store(StoreCashHandoverRequest $request)
    {
        return $this->idempotency->run($request, 'cash-accounts.handovers.create', $this->context->id(), fn () => ApiResponse::success(new CashHandoverResource($this->service->create($request->validated(), $this->context->get(), $request)), 201));
    }

    public function confirm(Request $request, string $id)
    {
        $input = $request->validate(['type' => ['required', 'in:outgoing,incoming'], 'comments' => ['nullable', 'string', 'max:5000']]);

        return $this->idempotency->run($request, 'cash-accounts.handovers.confirm', $this->context->id(), fn () => ApiResponse::success(new CashHandoverResource($this->service->confirm($this->handover($id), $input['type'], $input['comments'] ?? '', $this->context->get(), $request))));
    }

    public function approve(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.handovers.approve', $this->context->id(), fn () => ApiResponse::success(new CashHandoverResource($this->service->approve($this->handover($id), $this->context->get(), $request))));
    }

    public function complete(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.handovers.complete', $this->context->id(), fn () => ApiResponse::success(new CashHandoverResource($this->service->complete($this->handover($id), $this->context->get(), $request))));
    }

    public function cancel(ReasonRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.handovers.cancel', $this->context->id(), fn () => ApiResponse::success(new CashHandoverResource($this->service->cancel($this->handover($id), $request->validated('reason'), $this->context->get(), $request))));
    }

    public function uploadEvidence(EvidenceRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.cash-counts.evidence.upload', $this->context->id(), fn () => ApiResponse::success(new AttachmentResource($this->attachments->upload($request->file('file'), $this->handover($id), $this->context->get(), $request)), 201));
    }

    public function evidence(string $id)
    {
        return AttachmentResource::collection($this->handover($id)->attachments()->orderByDesc('created_at')->paginate(50));
    }

    private function handover(string $id): CashCustodianHandover
    {
        return CashCustodianHandover::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }
}
