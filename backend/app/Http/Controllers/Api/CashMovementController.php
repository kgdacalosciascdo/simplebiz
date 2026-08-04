<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccounts\EvidenceRequest;
use App\Http\Requests\CashAccounts\PostCashMovementRequest;
use App\Http\Requests\CashAccounts\ReasonRequest;
use App\Http\Requests\CashAccounts\StoreCashMovementRequest;
use App\Http\Requests\CashAccounts\UpdateCashMovementRequest;
use App\Http\Resources\CashAccounts\AttachmentResource;
use App\Http\Resources\CashAccounts\CashMovementDocumentResource;
use App\Http\Resources\CashAccounts\CashMovementResource;
use App\Http\Resources\CashAccounts\MovementPurposeResource;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashMovementDocument;
use App\Models\CashMovementPurpose;
use App\Models\CashMovementStatusHistory;
use App\Services\AttachmentService;
use App\Services\CashMovementService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class CashMovementController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly CashMovementService $service, private readonly AttachmentService $attachments, private readonly IdempotencyService $idempotency) {}

    public function purposes()
    {
        return ApiResponse::success(MovementPurposeResource::collection(CashMovementPurpose::where('status', 'active')->whereIn('code', ['DIRECT_CASH_IN', 'DIRECT_CASH_OUT', 'INTERNAL_TRANSFER', 'DEPOSIT', 'WITHDRAWAL'])->orderBy('name')->get()));
    }

    public function index(Request $request, ?string $kind = null)
    {
        $query = CashMovementDocument::where('company_id', $this->context->id())->with(['purpose', 'account.currency', 'currency', 'reasonCode', 'movement', 'attachments']);
        if ($kind) {
            $query->whereHas('purpose', fn ($builder) => $builder->where('document_kind', $kind));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('cash_account_id')) {
            $query->where('cash_account_id', $request->string('cash_account_id'));
        }
        if ($request->filled('movement_purpose')) {
            $query->whereHas('purpose', fn ($builder) => $builder->where('code', $request->string('movement_purpose')));
        }
        if ($request->filled('q')) {
            $query->where(fn ($builder) => $builder->where('document_number', 'like', '%'.$request->string('q').'%')->orWhere('external_reference', 'like', '%'.$request->string('q').'%'));
        }

        return CashMovementDocumentResource::collection($query->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 20), 100)));
    }

    public function show(string $id)
    {
        return ApiResponse::success(new CashMovementDocumentResource($this->document($id)->load(['purpose', 'account.currency', 'currency', 'paymentMethod', 'reasonCode', 'movement', 'attachments'])));
    }

    public function store(StoreCashMovementRequest $request, string $kind)
    {
        return $this->idempotency->run($request, 'cash-accounts.'.$kind.'.create', $this->context->id(), function () use ($request, $kind) {
            return ApiResponse::success(new CashMovementDocumentResource($this->service->create($request->validated(), $this->context->get(), $request, $kind)), 201);
        });
    }

    public function update(UpdateCashMovementRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.movements.update', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashMovementDocumentResource($this->service->update($this->document($id)->load('purpose'), $request->validated(), $this->context->get(), $request)));
        });
    }

    public function submit(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.movements.submit', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashMovementDocumentResource($this->service->submit($this->document($id)->load('purpose'), $this->context->get(), $request)));
        });
    }

    public function review(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.movements.review', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashMovementDocumentResource($this->service->review($this->document($id), $this->context->get(), $request)));
        });
    }

    public function approve(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.movements.approve', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashMovementDocumentResource($this->service->approve($this->document($id), $this->context->get(), $request)));
        });
    }

    public function cancel(ReasonRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.movements.cancel', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashMovementDocumentResource($this->service->cancel($this->document($id), $request->validated('reason'), $this->context->get(), $request)));
        });
    }

    public function post(PostCashMovementRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.movements.post', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashMovementDocumentResource($this->service->post($this->document($id)->load('purpose'), $this->context->get(), $request)));
        });
    }

    public function reverse(ReasonRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.movements.reverse', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new CashMovementDocumentResource($this->service->reverse($this->document($id)->load('purpose'), $request->validated('reason'), $this->context->get(), $request)));
        });
    }

    public function uploadEvidence(EvidenceRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.movements.evidence', $this->context->id(), function () use ($request, $id) {
            return ApiResponse::success(new AttachmentResource($this->attachments->upload($request->file('file'), $this->document($id), $this->context->get(), $request)), 201);
        });
    }

    public function evidence(string $id)
    {
        return AttachmentResource::collection($this->document($id)->attachments()->orderByDesc('created_at')->paginate(50));
    }

    public function downloadEvidence(Request $request, string $id, string $attachmentId)
    {
        $attachment = $this->document($id)->attachments()->whereKey($attachmentId)->firstOrFail();

        return $this->attachments->download($attachment, $this->context->get(), $request);
    }

    public function history(string $id)
    {
        $document = $this->document($id);

        return ApiResponse::success(CashMovementStatusHistory::where('company_id', $this->context->id())->where('document_type', CashMovementDocument::class)->where('document_id', $document->id)->orderBy('created_at')->get());
    }

    public function auditHistory(string $id)
    {
        return ApiResponse::success(AuditLog::where('company_id', $this->context->id())->where('entity_id', $this->document($id)->id)->orderByDesc('created_at')->paginate(50));
    }

    public function movementHistory(Request $request)
    {
        $query = CashMovement::where('company_id', $this->context->id())->with('account.currency');
        if ($request->filled('cash_account_id')) {
            $query->where('cash_account_id', $request->string('cash_account_id'));
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->string('direction'));
        }
        if ($request->filled('movement_status')) {
            $query->where('movement_status', $request->string('movement_status'));
        }
        if ($request->filled('clearing_status')) {
            $query->where('clearing_status', $request->string('clearing_status'));
        }
        if ($request->filled('reconciliation_status')) {
            $query->where('reconciliation_status', $request->string('reconciliation_status'));
        }
        if ($request->filled('q')) {
            $query->where('source_reference', 'like', '%'.$request->string('q').'%');
        }

        return CashMovementResource::collection($query->orderByDesc('business_date')->orderByDesc('posted_at')->paginate(min((int) $request->integer('per_page', 50), 100)));
    }

    private function document(string $id): CashMovementDocument
    {
        return CashMovementDocument::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }
}
