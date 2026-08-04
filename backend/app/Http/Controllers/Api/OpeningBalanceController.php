<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccounts\EvidenceRequest;
use App\Http\Requests\CashAccounts\ReasonRequest;
use App\Http\Requests\CashAccounts\StoreOpeningBalanceRequest;
use App\Http\Requests\CashAccounts\UpdateOpeningBalanceRequest;
use App\Http\Resources\CashAccounts\AttachmentResource;
use App\Http\Resources\CashAccounts\OpeningBalanceResource;
use App\Models\AuditLog;
use App\Models\OpeningBalance;
use App\Services\AttachmentService;
use App\Services\OpeningBalanceService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class OpeningBalanceController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly OpeningBalanceService $service, private readonly AttachmentService $attachments, private readonly IdempotencyService $idempotency) {}

    public function index(Request $request)
    {
        $query = OpeningBalance::where('company_id', $this->context->id())->with(['account', 'currency', 'reasonCode', 'attachments', 'movement']);
        if ($request->filled('cash_account_id')) {
            $query->where('cash_account_id', $request->string('cash_account_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return OpeningBalanceResource::collection($query->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 20), 100)));
    }

    public function store(StoreOpeningBalanceRequest $request)
    {
        return $this->idempotency->run($request, 'cash-accounts.opening-balance.create', $this->context->id(), function () use ($request) {
            $opening = $this->service->create($request->validated(), $this->context->get(), $request);

            return ApiResponse::success(new OpeningBalanceResource($opening), 201);
        });
    }

    public function show(string $id)
    {
        return ApiResponse::success(new OpeningBalanceResource($this->opening($id)->load(['account.currency', 'reasonCode', 'attachments', 'movement'])));
    }

    public function update(UpdateOpeningBalanceRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.opening-balance.update', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new OpeningBalanceResource($this->service->update($this->opening($id), $request->validated(), $this->context->get(), $request)));
        });
    }

    public function submit(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.opening-balance.submit', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new OpeningBalanceResource($this->service->submit($this->opening($id), $this->context->get(), $request)));
        });
    }

    public function approve(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.opening-balance.approve', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new OpeningBalanceResource($this->service->approve($this->opening($id), $this->context->get(), $request)));
        });
    }

    public function reject(ReasonRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.opening-balance.return', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new OpeningBalanceResource($this->service->returnForCorrection($this->opening($id), $request->validated('reason'), $this->context->get(), $request)));
        });
    }

    public function post(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.opening-balance.post', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new OpeningBalanceResource($this->service->post($this->opening($id), $this->context->get(), $request)));
        });
    }

    public function reverse(ReasonRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.opening-balance.reverse', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new OpeningBalanceResource($this->service->reverse($this->opening($id), $request->validated('reason'), $this->context->get(), $request)));
        });
    }

    public function uploadEvidence(EvidenceRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.opening-balance.evidence', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new AttachmentResource($this->attachments->upload($request->file('file'), $this->opening($id), $this->context->get(), $request)), 201);
        });
    }

    public function evidence(string $id)
    {
        return AttachmentResource::collection($this->opening($id)->attachments()->orderByDesc('created_at')->paginate(50));
    }

    public function downloadEvidence(Request $request, string $id, string $attachmentId)
    {
        $opening = $this->opening($id);
        $attachment = $opening->attachments()->whereKey($attachmentId)->firstOrFail();

        return $this->attachments->download($attachment, $this->context->get(), $request);
    }

    public function history(string $id)
    {
        $opening = $this->opening($id);

        return ApiResponse::success(AuditLog::where('company_id', $this->context->id())->where('entity_id', $opening->id)->orderByDesc('created_at')->paginate(50));
    }

    private function opening(string $id): OpeningBalance
    {
        return OpeningBalance::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }
}
