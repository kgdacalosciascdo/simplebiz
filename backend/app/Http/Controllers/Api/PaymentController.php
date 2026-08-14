<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccounts\EvidenceRequest;
use App\Http\Requests\Payments\AdvanceApplicationRequest;
use App\Http\Requests\Payments\AllocationCorrectionRequest;
use App\Http\Requests\Payments\BatchActionRequest;
use App\Http\Requests\Payments\BatchItemsRequest;
use App\Http\Requests\Payments\CheckActionRequest;
use App\Http\Requests\Payments\ConvertPaymentRequest;
use App\Http\Requests\Payments\PaymentActionRequest;
use App\Http\Requests\Payments\PaymentAllocationRequest;
use App\Http\Requests\Payments\PaymentCorrectionRequest;
use App\Http\Requests\Payments\PaymentRecoveryRequest;
use App\Http\Requests\Payments\StorePaymentBatchRequest;
use App\Http\Requests\Payments\StorePaymentRequest;
use App\Http\Requests\Payments\StorePaymentRequestRequest;
use App\Http\Requests\Payments\StoreSupplierAdvanceRequest;
use App\Http\Resources\CashAccounts\AttachmentResource;
use App\Http\Resources\Payments\CheckResource;
use App\Http\Resources\Payments\PaymentAdvanceResource;
use App\Http\Resources\Payments\PaymentBatchResource;
use App\Http\Resources\Payments\PaymentRequestResource;
use App\Http\Resources\Payments\PaymentResource;
use App\Http\Resources\Payments\PaymentVoucherResource;
use App\Http\Resources\Payments\RemittanceAdviceResource;
use App\Http\Resources\Purchases\PayableResource;
use App\Models\PaymentAdvance;
use App\Models\PaymentAllocation;
use App\Models\PaymentBatch;
use App\Models\PaymentInstruction;
use App\Models\PaymentInstrument;
use App\Models\PaymentRequest;
use App\Services\AttachmentService;
use App\Services\PaymentCompletionService;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly PaymentService $service, private readonly PaymentCompletionService $completion, private readonly AttachmentService $attachments, private readonly IdempotencyService $idempotency) {}

    public function lookups()
    {
        return ApiResponse::success($this->service->lookups($this->context->get()));
    }

    public function summary()
    {
        return ApiResponse::success($this->service->summary($this->context->get()));
    }

    public function workbench(Request $request)
    {
        [$items, $meta] = $this->service->workbench($this->context->get(), $request);

        return ApiResponse::success(PayableResource::collection($items)->resolve(), 200, $meta);
    }

    public function requests(Request $request)
    {
        [$items, $meta] = $this->service->requests($this->context->get(), $request);

        return ApiResponse::success(PaymentRequestResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeRequest(StorePaymentRequestRequest $request)
    {
        return $this->idempotency->run($request, 'payments.request.create', $this->context->id(), fn () => ApiResponse::success((new PaymentRequestResource($this->service->createRequest($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function showRequest(string $id)
    {
        $item = PaymentRequest::where('company_id', $this->context->id())->with(['supplier', 'branch', 'currency', 'sources.payable', 'statusHistory', 'payment'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new PaymentRequestResource($item))->resolve());
    }

    public function requestAction(PaymentActionRequest $request, string $id)
    {
        $item = PaymentRequest::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $action = (string) $request->route('action');

        return $this->idempotency->run($request, 'payments.request.'.$action, $this->context->id(), fn () => ApiResponse::success((new PaymentRequestResource($this->service->transitionRequest($item, $action, $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null)))->resolve()));
    }

    public function convert(ConvertPaymentRequest $request, string $id)
    {
        $source = PaymentRequest::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $input = $request->validated();
        $input += ['supplier_id' => $source->supplier_id, 'branch_id' => $source->branch_id, 'currency_id' => $source->currency_id, 'sources' => $source->sources()->get()->map(fn ($sourceLine) => ['payable_open_item_id' => $sourceLine->payable_open_item_id, 'amount' => (string) $sourceLine->proposed_amount])->all()];

        return $this->idempotency->run($request, 'payments.request.convert', $this->context->id(), fn () => ApiResponse::success((new PaymentResource($this->service->createPayment($input, $this->context->get(), $request, $source)))->resolve(), 201));
    }

    public function payments(Request $request)
    {
        [$items, $meta] = $this->service->payments($this->context->get(), $request);

        return ApiResponse::success(PaymentResource::collection($items)->resolve(), 200, $meta);
    }

    public function store(StorePaymentRequest $request)
    {
        return $this->idempotency->run($request, 'payments.instruction.create', $this->context->id(), fn () => ApiResponse::success((new PaymentResource($this->service->createPayment($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function storeAdvance(StoreSupplierAdvanceRequest $request)
    {
        return $this->idempotency->run($request, 'payments.advance.create', $this->context->id(), fn () => ApiResponse::success((new PaymentResource($this->service->createAdvance($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function show(string $id)
    {
        $item = PaymentInstruction::where('company_id', $this->context->id())->with(['request', ...$this->paymentRelations()])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new PaymentResource($item))->resolve());
    }

    public function action(PaymentActionRequest $request, string $id)
    {
        $item = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $action = (string) $request->route('action');

        return $this->idempotency->run($request, 'payments.instruction.'.$action, $this->context->id(), fn () => ApiResponse::success((new PaymentResource($this->service->transitionPayment($item, $action, $this->context->get(), $request, $request->validated())))->resolve()));
    }

    public function allocate(PaymentAllocationRequest $request, string $id)
    {
        $item = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'payments.instruction.allocate', $this->context->id(), fn () => ApiResponse::success((new PaymentResource($this->service->allocate($item, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function timeline(string $id)
    {
        $item = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return ApiResponse::success($this->service->timeline($item, $this->context->get()));
    }

    public function remittance(Request $request, string $id)
    {
        $item = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'payments.remittance.create', $this->context->id(), fn () => ApiResponse::success((new RemittanceAdviceResource($this->service->remittance($item, $this->context->get(), $request)))->resolve(), 201));
    }

    public function reverse(PaymentCorrectionRequest $request, string $id)
    {
        $item = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'payments.correction.reverse', $this->context->id(), fn () => ApiResponse::success((new PaymentResource($this->completion->reverse($item, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function voucher(Request $request, string $id)
    {
        $item = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $reprint = $request->boolean('reprint');

        return $this->idempotency->run($request, 'payments.voucher.'.($reprint ? 'reprint' : 'issue'), $this->context->id(), fn () => ApiResponse::success((new PaymentVoucherResource($this->completion->voucher($item, $this->context->get(), $request, $reprint)))->resolve(), $reprint ? 200 : 201));
    }

    public function uploadEvidence(EvidenceRequest $request, string $id)
    {
        $payment = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'payments.evidence.upload', $this->context->id(), fn () => ApiResponse::success(new AttachmentResource($this->attachments->upload($request->file('file'), $payment, $this->context->get(), $request, 'payments')), 201));
    }

    public function evidence(string $id)
    {
        $payment = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return AttachmentResource::collection($payment->attachments()->orderByDesc('created_at')->paginate(50));
    }

    public function downloadEvidence(Request $request, string $id, string $attachmentId)
    {
        $payment = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $attachment = $payment->attachments()->whereKey($attachmentId)->firstOrFail();

        return $this->attachments->download($attachment, $this->context->get(), $request);
    }

    public function unapply(AllocationCorrectionRequest $request, string $id, string $allocationId)
    {
        $payment = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $allocation = PaymentAllocation::where('company_id', $this->context->id())->where('payment_instruction_id', $payment->id)->whereKey($allocationId)->firstOrFail();

        return $this->idempotency->run($request, 'payments.allocation.unapply', $this->context->id(), fn () => ApiResponse::success((new PaymentResource($this->completion->unapply($payment, $allocation, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function reallocate(AllocationCorrectionRequest $request, string $id, string $allocationId)
    {
        $payment = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $allocation = PaymentAllocation::where('company_id', $this->context->id())->where('payment_instruction_id', $payment->id)->whereKey($allocationId)->firstOrFail();

        return $this->idempotency->run($request, 'payments.allocation.reallocate', $this->context->id(), fn () => ApiResponse::success((new PaymentResource($this->completion->reallocate($payment, $allocation, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function advances(Request $request)
    {
        [$items, $meta] = $this->completion->advances($this->context->get(), $request);

        return ApiResponse::success(PaymentAdvanceResource::collection($items)->resolve(), 200, $meta);
    }

    public function applyAdvance(AdvanceApplicationRequest $request, string $id)
    {
        $advance = PaymentAdvance::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'payments.advance.apply', $this->context->id(), fn () => ApiResponse::success((new PaymentResource($this->completion->applyAdvance($advance, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function checks(Request $request)
    {
        [$items, $meta] = $this->completion->checks($this->context->get(), $request);

        return ApiResponse::success(CheckResource::collection($items)->resolve(), 200, $meta);
    }

    public function checkAction(CheckActionRequest $request, string $id, string $action)
    {
        $this->authorizeDynamic($request, ['print' => 'payments.checks.print', 'sign' => 'payments.checks.sign', 'stop' => 'payments.checks.stop', 'void' => 'payments.checks.void', 'replace' => 'payments.checks.replace', 'stale' => 'payments.checks.stop', 'release' => 'payments.checks.sign'][$action] ?? 'payments.checks.view');
        $instrument = PaymentInstrument::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'payments.check.'.$action, $this->context->id(), fn () => ApiResponse::success((new CheckResource($this->completion->checkAction($instrument, $action, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function recover(PaymentRecoveryRequest $request, string $id)
    {
        $this->authorizeDynamic($request, $request->validated('resolution') === 'retry' ? 'payments.recovery.retry' : 'payments.recovery.resolve');
        $item = PaymentInstruction::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'payments.recovery.resolve', $this->context->id(), fn () => ApiResponse::success((new PaymentResource($this->completion->recover($item, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function batches(Request $request)
    {
        [$items, $meta] = $this->completion->batches($this->context->get(), $request);

        return ApiResponse::success(PaymentBatchResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeBatch(StorePaymentBatchRequest $request)
    {
        return $this->idempotency->run($request, 'payments.batch.create', $this->context->id(), fn () => ApiResponse::success((new PaymentBatchResource($this->completion->createBatch($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function showBatch(string $id)
    {
        $batch = PaymentBatch::where('company_id', $this->context->id())->whereKey($id)->with(['items.payment.supplier', 'items.payment.currency', 'statusHistory'])->firstOrFail();

        return ApiResponse::success((new PaymentBatchResource($batch))->resolve());
    }

    public function batchAction(BatchActionRequest $request, string $id, string $action)
    {
        $this->authorizeDynamic($request, ['validate' => 'payments.batches.validate', 'submit' => 'payments.batches.submit', 'approve' => 'payments.batches.approve', 'generate' => 'payments.batches.generate', 'release' => 'payments.batches.release', 'execute' => 'payments.batches.execute', 'close' => 'payments.batches.close', 'cancel' => 'payments.batches.update'][$action] ?? 'payments.batches.update');
        $batch = PaymentBatch::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'payments.batch.'.$action, $this->context->id(), fn () => ApiResponse::success((new PaymentBatchResource($this->completion->batchAction($batch, $action, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function batchItems(BatchItemsRequest $request, string $id)
    {
        $batch = PaymentBatch::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'payments.batch.items.'.$request->validated('action'), $this->context->id(), fn () => ApiResponse::success((new PaymentBatchResource($this->completion->batchItems($batch, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function attention()
    {
        return ApiResponse::success($this->completion->attention($this->context->get()));
    }

    public function report(Request $request)
    {
        [$items, $meta] = $this->completion->report($this->context->get(), $request);

        return ApiResponse::success($items, 200, $meta);
    }

    private function paymentRelations(): array
    {
        return ['supplier', 'branch', 'currency', 'paymentMethod', 'cashAccount.currency', 'sources.payable', 'sources.expenseObligation.expense', 'sources.reimbursementObligation.claim', 'allocations', 'allocations.reimbursementObligation.claim', 'approvals', 'instruments', 'attempts', 'confirmations', 'statusHistory', 'remittanceAdvice', 'advance', 'corrections', 'batchItems.batch', 'voucher', 'attachments'];
    }

    private function authorizeDynamic(Request $request, string $permission): void
    {
        if (! $request->user()?->hasPermission($permission, $this->context->id())) {
            abort(403, 'You are not authorized for this payment action.');
        }
    }
}
