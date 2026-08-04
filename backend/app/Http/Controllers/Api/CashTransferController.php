<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccounts\EvidenceRequest;
use App\Http\Requests\CashAccounts\ReasonRequest;
use App\Http\Requests\CashAccounts\StoreCashTransferRequest;
use App\Http\Requests\CashAccounts\UpdateCashTransferRequest;
use App\Http\Resources\CashAccounts\AttachmentResource;
use App\Http\Resources\CashAccounts\CashTransferResource;
use App\Models\CashMovementStatusHistory;
use App\Models\CashTransferDocument;
use App\Services\AttachmentService;
use App\Services\CashTransferService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class CashTransferController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly CashTransferService $service, private readonly AttachmentService $attachments, private readonly IdempotencyService $idempotency) {}

    public function index(Request $request)
    {
        $query = CashTransferDocument::where('company_id', $this->context->id())->with(['sourceAccount.currency', 'destinationAccount.currency', 'currency', 'reasonCode', 'legs.movement', 'attachments']);
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('purpose')) {
            $query->where('purpose', $request->string('purpose'));
        }
        if ($request->filled('cash_account_id')) {
            $query->where(fn ($builder) => $builder->where('source_cash_account_id', $request->string('cash_account_id'))->orWhere('destination_cash_account_id', $request->string('cash_account_id')));
        }
        if ($request->filled('q')) {
            $query->where(fn ($builder) => $builder->where('document_number', 'like', '%'.$request->string('q').'%')->orWhere('external_reference', 'like', '%'.$request->string('q').'%'));
        }

        return CashTransferResource::collection($query->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 20), 100)));
    }

    public function show(string $id)
    {
        return ApiResponse::success(new CashTransferResource($this->document($id)->load(['sourceAccount.currency', 'destinationAccount.currency', 'currency', 'paymentMethod', 'reasonCode', 'legs.movement', 'attachments'])));
    }

    public function store(StoreCashTransferRequest $request)
    {
        return $this->idempotency->run($request, 'cash-accounts.transfers.create', $this->context->id(), function () use ($request) {
            return ApiResponse::success(new CashTransferResource($this->service->create($request->validated(), $this->context->get(), $request)), 201);
        });
    }

    public function update(UpdateCashTransferRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.transfers.update', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashTransferResource($this->service->update($this->document($id), $request->validated(), $this->context->get(), $request)));
        });
    }

    public function submit(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.transfers.submit', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashTransferResource($this->service->submit($this->document($id), $this->context->get(), $request)));
        });
    }

    public function review(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.transfers.review', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashTransferResource($this->service->review($this->document($id), $this->context->get(), $request)));
        });
    }

    public function approve(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.transfers.approve', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashTransferResource($this->service->approve($this->document($id), $this->context->get(), $request)));
        });
    }

    public function cancel(ReasonRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.transfers.cancel', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashTransferResource($this->service->cancel($this->document($id), $request->validated('reason'), $this->context->get(), $request)));
        });
    }

    public function post(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.transfers.post', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashTransferResource($this->service->post($this->document($id), $this->context->get(), $request)));
        });
    }

    public function reverse(ReasonRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.transfers.reverse', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashTransferResource($this->service->reverse($this->document($id), $request->validated('reason'), $this->context->get(), $request)));
        });
    }

    public function uploadEvidence(EvidenceRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.transfers.evidence', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new AttachmentResource($this->attachments->upload($request->file('file'), $this->document($id), $this->context->get(), $request)), 201);
        });
    }

    public function evidence(string $id)
    {
        return AttachmentResource::collection($this->document($id)->attachments()->orderByDesc('created_at')->paginate(50));
    }

    public function downloadEvidence(Request $request, string $id, string $attachmentId)
    {
        return $this->attachments->download($this->document($id)->attachments()->whereKey($attachmentId)->firstOrFail(), $this->context->get(), $request);
    }

    public function history(string $id)
    {
        $document = $this->document($id);

        return ApiResponse::success(CashMovementStatusHistory::where('company_id', $this->context->id())->where('document_type', CashTransferDocument::class)->where('document_id', $document->id)->orderBy('created_at')->get());
    }

    private function document(string $id): CashTransferDocument
    {
        return CashTransferDocument::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }
}
