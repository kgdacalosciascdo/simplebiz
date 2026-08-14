<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\GoodsReceiptRequest;
use App\Http\Requests\Purchases\MatchExceptionRequest;
use App\Http\Requests\Purchases\PayableHoldRequest;
use App\Http\Requests\Purchases\PurchaseActionRequest;
use App\Http\Requests\Purchases\PurchaseOrderRequest;
use App\Http\Requests\Purchases\PurchaseReturnRequest;
use App\Http\Requests\Purchases\SupplierAdjustmentRequest;
use App\Http\Requests\Purchases\SupplierInvoiceCorrectionRequest;
use App\Http\Requests\Purchases\SupplierInvoiceRequest;
use App\Http\Resources\Purchases\GoodsReceiptResource;
use App\Http\Resources\Purchases\PayableEffectResource;
use App\Http\Resources\Purchases\PayableResource;
use App\Http\Resources\Purchases\PurchaseMatchExceptionResource;
use App\Http\Resources\Purchases\PurchaseOrderResource;
use App\Http\Resources\Purchases\PurchaseReturnResource;
use App\Http\Resources\Purchases\SupplierAdjustmentResource;
use App\Http\Resources\Purchases\SupplierInvoiceCorrectionResource;
use App\Http\Resources\Purchases\SupplierInvoiceResource;
use App\Models\GoodsReceipt;
use App\Models\PayableOpenItem;
use App\Models\PurchaseMatchException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SupplierAdjustment;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceCorrection;
use App\Services\PurchasingService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class PurchasingController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly PurchasingService $service, private readonly IdempotencyService $idempotency) {}

    public function lookups()
    {
        return ApiResponse::success($this->service->lookups($this->context->get()));
    }

    public function summary()
    {
        return ApiResponse::success($this->service->summary($this->context->get()));
    }

    public function orders(Request $request)
    {
        [$items, $meta] = $this->service->orders($this->context->get(), $request);

        return ApiResponse::success(PurchaseOrderResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeOrder(PurchaseOrderRequest $request)
    {
        return $this->idempotency->run($request, 'purchases.order.create', $this->context->id(), fn () => ApiResponse::success((new PurchaseOrderResource($this->service->createOrder($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function showOrder(string $id)
    {
        $item = PurchaseOrder::where('company_id', $this->context->id())->with(['lines', 'supplier', 'currency', 'paymentTerm', 'statusHistory'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new PurchaseOrderResource($item))->resolve());
    }

    public function updateOrder(PurchaseOrderRequest $request, string $id)
    {
        $item = $this->order($id);

        return $this->idempotency->run($request, 'purchases.order.update', $this->context->id(), fn () => ApiResponse::success((new PurchaseOrderResource($this->service->updateOrder($item, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function orderAction(PurchaseActionRequest $request, string $id)
    {
        $item = $this->order($id);
        $result = $this->service->transitionOrder($item, (string) $request->route('action'), $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null);

        return ApiResponse::success((new PurchaseOrderResource($result))->resolve());
    }

    public function amendOrder(PurchaseOrderRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'purchases.order.amend', $this->context->id(), fn () => ApiResponse::success((new PurchaseOrderResource($this->service->amendOrder($this->order($id), $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function reopenOrder(PurchaseActionRequest $request, string $id)
    {
        return ApiResponse::success((new PurchaseOrderResource($this->service->reopenOrder($this->order($id), (string) $request->validated()['reason'], $this->context->get(), $request, $request->validated()['version'] ?? null)))->resolve());
    }

    public function orderHistory(string $id)
    {
        return ApiResponse::success($this->order($id)->statusHistory()->latest()->get());
    }

    public function receipts(Request $request)
    {
        [$items, $meta] = $this->service->receipts($this->context->get(), $request);

        return ApiResponse::success(GoodsReceiptResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeReceipt(GoodsReceiptRequest $request)
    {
        return $this->idempotency->run($request, 'purchases.receipt.create', $this->context->id(), fn () => ApiResponse::success((new GoodsReceiptResource($this->service->createReceipt($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function showReceipt(string $id)
    {
        $item = GoodsReceipt::where('company_id', $this->context->id())->with(['lines', 'purchaseOrder', 'supplier'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new GoodsReceiptResource($item))->resolve());
    }

    public function receiptAction(PurchaseActionRequest $request, string $id)
    {
        $item = $this->receipt($id);
        $result = $this->service->transitionReceipt($item, (string) $request->route('action'), $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null);

        return ApiResponse::success((new GoodsReceiptResource($result))->resolve());
    }

    public function invoices(Request $request)
    {
        [$items, $meta] = $this->service->invoices($this->context->get(), $request);

        return ApiResponse::success(SupplierInvoiceResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeInvoice(SupplierInvoiceRequest $request)
    {
        return $this->idempotency->run($request, 'purchases.invoice.create', $this->context->id(), fn () => ApiResponse::success((new SupplierInvoiceResource($this->service->createInvoice($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function showInvoice(string $id)
    {
        $item = SupplierInvoice::where('company_id', $this->context->id())->with(['lines', 'supplier', 'currency', 'purchaseOrder', 'payable', 'matchExceptions'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new SupplierInvoiceResource($item))->resolve());
    }

    public function invoiceAction(PurchaseActionRequest $request, string $id)
    {
        $item = $this->invoice($id);
        $result = $this->service->transitionInvoice($item, (string) $request->route('action'), $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null);

        return ApiResponse::success((new SupplierInvoiceResource($result))->resolve());
    }

    public function storeInvoiceCorrection(SupplierInvoiceCorrectionRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'purchases.invoice.correction.create', $this->context->id(), fn () => ApiResponse::success((new SupplierInvoiceCorrectionResource($this->service->createInvoiceCorrection($this->invoice($id), $request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function showInvoiceCorrection(string $id)
    {
        $item = SupplierInvoiceCorrection::where('company_id', $this->context->id())->with(['originalInvoice', 'supplier', 'currency'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new SupplierInvoiceCorrectionResource($item))->resolve());
    }

    public function invoiceCorrectionAction(PurchaseActionRequest $request, string $id)
    {
        $correction = SupplierInvoiceCorrection::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return ApiResponse::success((new SupplierInvoiceCorrectionResource($this->service->transitionInvoiceCorrection($correction, (string) $request->route('action'), $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null)))->resolve());
    }

    public function resolveMatchException(MatchExceptionRequest $request, string $id)
    {
        return ApiResponse::success((new PurchaseMatchExceptionResource($this->service->resolveMatchException(PurchaseMatchException::where('company_id', $this->context->id())->whereKey($id)->firstOrFail(), $request->validated(), $this->context->get(), $request)))->resolve());
    }

    public function payables(Request $request)
    {
        [$items, $meta] = $this->service->payables($this->context->get(), $request);

        return ApiResponse::success(PayableResource::collection($items)->resolve(), 200, $meta);
    }

    public function aging()
    {
        return ApiResponse::success($this->service->aging($this->context->get()));
    }

    public function payable(string $id)
    {
        $item = PayableOpenItem::where('company_id', $this->context->id())->with(['supplier', 'currency', 'sourceInvoice'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new PayableResource($item))->resolve());
    }

    public function payableEffects(string $id)
    {
        $item = PayableOpenItem::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $result = $this->service->payableEffects($item, $this->context->get());

        return ApiResponse::success(['effects' => PayableEffectResource::collection($result['effects'])->resolve(), 'hold_history' => $result['hold_history']]);
    }

    public function payableHold(PayableHoldRequest $request, string $id)
    {
        return ApiResponse::success((new PayableResource($this->service->holdPayable(PayableOpenItem::where('company_id', $this->context->id())->whereKey($id)->firstOrFail(), (string) $request->route('action'), $request->validated()['reason'], $this->context->get(), $request, $request->validated()['version'] ?? null)))->resolve());
    }

    public function ledger(Request $request, string $supplierId)
    {
        return ApiResponse::success($this->service->ledger($supplierId, $this->context->get(), $request));
    }

    public function attention()
    {
        return ApiResponse::success($this->service->attention($this->context->get()));
    }

    public function report(Request $request, string $report)
    {
        return ApiResponse::success($this->service->reports($report, $this->context->get(), $request));
    }

    public function returns(Request $request)
    {
        [$items, $meta] = $this->service->returns($this->context->get(), $request);

        return ApiResponse::success(PurchaseReturnResource::collection($items)->resolve(), 200, $meta);
    }

    public function eligibleReturnLines(Request $request)
    {
        return ApiResponse::success($this->service->eligibleReturnLines($this->context->get(), $request));
    }

    public function storeReturn(PurchaseReturnRequest $request)
    {
        return $this->idempotency->run($request, 'purchases.return.create', $this->context->id(), fn () => ApiResponse::success((new PurchaseReturnResource($this->service->createReturn($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function showReturn(string $id)
    {
        $item = PurchaseReturn::where('company_id', $this->context->id())->with(['lines', 'supplier', 'currency', 'purchaseOrder', 'goodsReceipt', 'statusHistory'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new PurchaseReturnResource($item))->resolve());
    }

    public function returnAction(PurchaseActionRequest $request, string $id)
    {
        return ApiResponse::success((new PurchaseReturnResource($this->service->transitionReturn(PurchaseReturn::where('company_id', $this->context->id())->whereKey($id)->firstOrFail(), (string) $request->route('action'), $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null)))->resolve());
    }

    public function adjustments(Request $request)
    {
        [$items, $meta] = $this->service->adjustments($this->context->get(), $request);

        return ApiResponse::success(SupplierAdjustmentResource::collection($items)->resolve(), 200, $meta);
    }

    public function invoiceCorrections(Request $request)
    {
        [$items, $meta] = $this->service->invoiceCorrections($this->context->get(), $request);

        return ApiResponse::success(SupplierInvoiceCorrectionResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeAdjustment(SupplierAdjustmentRequest $request)
    {
        return $this->idempotency->run($request, 'purchases.adjustment.create', $this->context->id(), fn () => ApiResponse::success((new SupplierAdjustmentResource($this->service->createAdjustment($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function showAdjustment(string $id)
    {
        $item = SupplierAdjustment::where('company_id', $this->context->id())->with(['lines', 'supplier', 'currency', 'statusHistory'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new SupplierAdjustmentResource($item))->resolve());
    }

    public function adjustmentAction(PurchaseActionRequest $request, string $id)
    {
        return ApiResponse::success((new SupplierAdjustmentResource($this->service->transitionAdjustment(SupplierAdjustment::where('company_id', $this->context->id())->whereKey($id)->firstOrFail(), (string) $request->route('action'), $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null)))->resolve());
    }

    public function history(Request $request)
    {
        return ApiResponse::success(['orders' => PurchaseOrderResource::collection(PurchaseOrder::where('company_id', $this->context->id())->with('supplier')->latest()->limit(30)->get())->resolve(), 'receipts' => GoodsReceiptResource::collection(GoodsReceipt::where('company_id', $this->context->id())->with(['supplier', 'purchaseOrder'])->latest()->limit(30)->get())->resolve(), 'invoices' => SupplierInvoiceResource::collection(SupplierInvoice::where('company_id', $this->context->id())->with(['supplier', 'currency'])->latest()->limit(30)->get())->resolve()]);
    }

    private function order(string $id): PurchaseOrder
    {
        return PurchaseOrder::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }

    private function receipt(string $id): GoodsReceipt
    {
        return GoodsReceipt::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }

    private function invoice(string $id): SupplierInvoice
    {
        return SupplierInvoice::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }
}
