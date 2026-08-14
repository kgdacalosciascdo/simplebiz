<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RegistryConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collections\ApplyPaymentRequest;
use App\Http\Requests\Collections\CollectionActivityRequest;
use App\Http\Requests\Collections\FailedTenderRequest;
use App\Http\Requests\Collections\OtherReceiptRequest;
use App\Http\Requests\Collections\ReceiptActionRequest;
use App\Http\Requests\Collections\RemittanceActionRequest;
use App\Http\Requests\Collections\RemittanceRequest;
use App\Http\Requests\Collections\ReprintReceiptRequest;
use App\Http\Requests\Collections\StoreReceiptRequest;
use App\Http\Resources\Collections\CashRemittanceResource;
use App\Http\Resources\Collections\PaymentApplicationResource;
use App\Http\Resources\Collections\ReceiptPrintableResource;
use App\Http\Resources\Collections\ReceiptResource;
use App\Http\Resources\Collections\UnappliedReceiptResource;
use App\Models\CashRemittance;
use App\Models\PaymentApplication;
use App\Models\Receipt;
use App\Services\CollectionsService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class CollectionsController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly CollectionsService $service, private readonly IdempotencyService $idempotency) {}

    public function summary()
    {
        return ApiResponse::success($this->service->summary($this->context->get()));
    }

    public function lookups()
    {
        return ApiResponse::success($this->service->lookups($this->context->get()));
    }

    public function index(Request $request)
    {
        [$items, $meta] = $this->service->listReceipts($this->context->get(), $request);

        return ApiResponse::success(ReceiptResource::collection($items)->resolve(), 200, $meta);
    }

    public function store(StoreReceiptRequest $request)
    {
        return $this->idempotency->run($request, 'collections.receipt.create', $this->context->id(), fn () => ApiResponse::success((new ReceiptResource($this->service->createDraft($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function show(string $id)
    {
        return ApiResponse::success((new ReceiptResource($this->receipt($id, true)))->resolve());
    }

    public function update(StoreReceiptRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'collections.receipt.update', $this->context->id(), fn () => ApiResponse::success((new ReceiptResource($this->service->updateDraft($this->receipt($id), $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function action(ReceiptActionRequest $request, string $id)
    {
        $action = $request->route('action');
        try {
            return $this->idempotency->run($request, 'collections.receipt.'.$action, $this->context->id(), fn () => ApiResponse::success((new ReceiptResource($this->service->transition($this->receipt($id), $action, $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null)))->resolve()));
        } catch (RegistryConflictException $e) {
            return ApiResponse::error($e->getMessage(), 409, $e->errors);
        }
    }

    public function otherAction(ReceiptActionRequest $request, string $id)
    {
        if ($this->receipt($id)->receipt_type !== 'other_receipt') {
            return ApiResponse::error('The selected record is not an Other Receipt.', 409);
        }

        return $this->action($request, $id);
    }

    public function history(string $id)
    {
        return ApiResponse::success($this->receipt($id)->statusHistory()->get());
    }

    public function otherReceiptTypes()
    {
        return ApiResponse::success($this->service->otherReceiptTypes($this->context->get()));
    }

    public function storeOtherReceipt(OtherReceiptRequest $request)
    {
        return $this->idempotency->run($request, 'collections.other-receipt.create', $this->context->id(), fn () => ApiResponse::success((new ReceiptResource($this->service->createOtherDraft($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function otherReceipts(Request $request)
    {
        $request->merge(['receipt_type' => 'other_receipt']);

        return $this->index($request);
    }

    public function reprint(ReprintReceiptRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'collections.receipt.reprint', $this->context->id(), fn () => ApiResponse::success($this->service->reprint($this->receipt($id), $request->validated(), $this->context->get(), $request)));
    }

    public function printable(Request $request, string $id)
    {
        return ApiResponse::success((new ReceiptPrintableResource($this->service->printable($this->receipt($id), $this->context->get(), $request)))->resolve());
    }

    public function failTender(FailedTenderRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'collections.tender.fail', $this->context->id(), fn () => ApiResponse::success($this->service->failTender($id, $request->validated(), $this->context->get(), $request)));
    }

    public function activities(Request $request)
    {
        [$items, $meta] = $this->service->listActivities($this->context->get(), $request);

        return ApiResponse::success($items, 200, $meta);
    }

    public function storeActivity(CollectionActivityRequest $request)
    {
        return $this->idempotency->run($request, 'collections.activity.create', $this->context->id(), fn () => ApiResponse::success($this->service->createActivity($request->validated(), $this->context->get(), $request), 201));
    }

    public function remittances(Request $request)
    {
        [$items, $meta] = $this->service->remittances($this->context->get(), $request);

        return ApiResponse::success(CashRemittanceResource::collection($items)->resolve(), 200, $meta);
    }

    public function showRemittance(string $id)
    {
        return ApiResponse::success((new CashRemittanceResource($this->service->showRemittance($id, $this->context->get())))->resolve());
    }

    public function report(Request $request, string $report)
    {
        return ApiResponse::success($this->service->report($report, $this->context->get(), $request));
    }

    public function storeRemittance(RemittanceRequest $request)
    {
        return $this->idempotency->run($request, 'collections.remittance.create', $this->context->id(), fn () => ApiResponse::success((new CashRemittanceResource($this->service->createRemittance($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function remittanceAction(RemittanceActionRequest $request, string $id)
    {
        $action = $request->route('action');

        return $this->idempotency->run($request, 'collections.remittance.'.$action, $this->context->id(), fn () => ApiResponse::success((new CashRemittanceResource($this->service->transitionRemittance(CashRemittance::where('company_id', $this->context->id())->whereKey($id)->firstOrFail(), $action, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function resolveVariance(RemittanceActionRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'collections.remittance.variance.resolve', $this->context->id(), fn () => ApiResponse::success($this->service->resolveVariance($id, $request->validated(), $this->context->get(), $request)));
    }

    public function unapplied(Request $request)
    {
        [$items, $meta] = $this->service->unapplied($this->context->get(), $request);

        return ApiResponse::success(UnappliedReceiptResource::collection($items)->resolve(), 200, $meta);
    }

    public function apply(ApplyPaymentRequest $request)
    {
        return $this->idempotency->run($request, 'collections.application.create', $this->context->id(), fn () => ApiResponse::success((new PaymentApplicationResource($this->service->applyUnapplied($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function reverseApplication(ReceiptActionRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'collections.application.reverse', $this->context->id(), function () use ($request, $id) {
            $application = PaymentApplication::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
            $reversed = $this->service->reverseApplication($application, (string) $request->validated()['reason'], $this->context->get(), $request);

            return ApiResponse::success((new PaymentApplicationResource($reversed))->resolve());
        });
    }

    public function ledger(Request $request, string $customerId)
    {
        return ApiResponse::success($this->service->ledger($this->context->get(), $customerId, $request));
    }

    private function receipt(string $id, bool $full = false): Receipt
    {
        return Receipt::where('company_id', $this->context->id())->when($full, fn ($q) => $q->with(['customer', 'currency', 'tenders.paymentMethod', 'tenders.cashAccount', 'applications.receivable', 'unapplied.customer', 'unapplied.currency', 'statusHistory']))->whereKey($id)->firstOrFail();
    }
}
