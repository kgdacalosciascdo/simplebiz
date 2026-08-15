<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RegistryConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\BillingStatementRequest;
use App\Http\Requests\Sales\PaidNowSaleRequest;
use App\Http\Requests\Sales\SaleActionRequest;
use App\Http\Requests\Sales\SalesAdjustmentRequest;
use App\Http\Requests\Sales\SalesCorrectionActionRequest;
use App\Http\Requests\Sales\SalesReturnRequest;
use App\Http\Requests\Sales\StoreSaleRequest;
use App\Http\Requests\Sales\UpdateSaleRequest;
use App\Http\Resources\Collections\ReceiptResource;
use App\Http\Resources\Sales\BillingStatementResource;
use App\Http\Resources\Sales\ReceivableResource;
use App\Http\Resources\Sales\SaleResource;
use App\Http\Resources\Sales\SalesAdjustmentResource;
use App\Http\Resources\Sales\SalesReturnResource;
use App\Models\BillingStatement;
use App\Models\ReceivableOpenItem;
use App\Models\Sale;
use App\Models\SalesAdjustment;
use App\Models\SalesReturn;
use App\Services\SalesCompletionService;
use App\Services\SalesPaidNowService;
use App\Services\SalesService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly SalesService $service, private readonly SalesCompletionService $completion, private readonly SalesPaidNowService $paidNow, private readonly IdempotencyService $idempotency, private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        [$items, $meta] = $this->service->list($this->context->get(), $request);

        return ApiResponse::success(SaleResource::collection($items)->resolve(), 200, $meta);
    }

    public function lookups()
    {
        return ApiResponse::success($this->service->lookups($this->context->get()));
    }

    public function summary()
    {
        return ApiResponse::success($this->service->summary($this->context->get()));
    }

    public function dashboard(Request $request)
    {
        return ApiResponse::success($this->service->dashboard($this->context->get(), $request));
    }

    public function attention()
    {
        return ApiResponse::success($this->service->attention($this->context->get()));
    }

    public function store(StoreSaleRequest $request)
    {
        return $this->idempotency->run($request, 'sales.create', $this->context->id(), fn () => ApiResponse::success((new SaleResource($this->service->createDraft($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function show(string $id)
    {
        $sale = $this->sale($id, ['lines', 'customer', 'currency', 'paymentTerm', 'receivable', 'statusHistory', 'inventoryMovements', 'salesReturns.lines', 'salesReturns.statusHistory', 'salesAdjustments.lines', 'salesAdjustments.statusHistory', 'receipts.currency', 'receipts.tenders.paymentMethod', 'receipts.tenders.cashAccount', 'receipts.applications', 'receipts.statusHistory']);

        return ApiResponse::success((new SaleResource($sale))->resolve());
    }

    public function update(UpdateSaleRequest $request, string $id)
    {
        $sale = $this->sale($id);

        return $this->idempotency->run($request, 'sales.update', $this->context->id(), fn () => ApiResponse::success((new SaleResource($this->service->updateDraft($sale, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function action(SaleActionRequest $request, string $id)
    {
        $action = $request->route('action');
        $sale = $this->sale($id);
        try {
            return $this->idempotency->run($request, 'sales.'.$action, $this->context->id(), fn () => ApiResponse::success((new SaleResource($this->service->transition($sale, $action, $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null)))->resolve()));
        } catch (RegistryConflictException $exception) {
            if ($action === 'post' && isset($exception->errors['dependency']) && ! ($exception->errors['version_conflict'] ?? false)) {
                $failed = Sale::where('company_id', $this->context->id())->whereKey($id)->first();
                if ($failed && $failed->status !== 'failed') {
                    $from = $failed->status;
                    $failed->status = 'failed';
                    $failed->blocked_code = $exception->errors['dependency'];
                    $failed->blocked_reason = $exception->getMessage();
                    $failed->version++;
                    $failed->save();
                    $this->audit->record($request, 'sales.post.failed', $failed, $this->context->id(), ['status' => $from], $failed->toArray(), $exception->getMessage(), 'Sales posting blocked', $exception->getMessage());
                }
            }

            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function paidNow(PaidNowSaleRequest $request, string $id)
    {
        if (! $request->user()?->hasPermission('collections.receipts.create', $this->context->id()) || ! $request->user()?->hasPermission('collections.receipts.post', $this->context->id())) {
            return ApiResponse::error('Your account is not authorized to post the linked customer receipt for paid-now completion.', 403);
        }

        try {
            return $this->idempotency->run($request, 'sales.paid-now', $this->context->id(), function () use ($request, $id) {
                $result = $this->paidNow->complete($this->sale($id), $request->validated(), $this->context->get(), $request);

                return ApiResponse::success([
                    'sale' => (new SaleResource($result['sale']))->resolve(),
                    'receipt' => (new ReceiptResource($result['receipt']))->resolve(),
                ]);
            });
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function history(string $id)
    {
        $sale = $this->sale($id);

        return ApiResponse::success($sale->statusHistory()->get());
    }

    public function reverse(SalesCorrectionActionRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'sales.reverse', $this->context->id(), fn () => ApiResponse::success((new SaleResource($this->completion->reverseSale($this->sale($id), $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null)))->resolve()));
    }

    public function eligibleReturnLines(Request $request)
    {
        return ApiResponse::success($this->completion->eligibleReturnLines($this->context->get(), $request));
    }

    public function returns(Request $request)
    {
        [$items, $meta] = $this->completion->returns($this->context->get(), $request);

        return ApiResponse::success(SalesReturnResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeReturn(SalesReturnRequest $request)
    {
        return $this->idempotency->run($request, 'sales.return.create', $this->context->id(), fn () => ApiResponse::success((new SalesReturnResource($this->completion->createReturn($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function showReturn(string $id)
    {
        $return = SalesReturn::where('company_id', $this->context->id())->with(['sale', 'customer', 'currency', 'lines', 'statusHistory'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new SalesReturnResource($return))->resolve());
    }

    public function returnAction(SalesCorrectionActionRequest $request, string $id)
    {
        return ApiResponse::success((new SalesReturnResource($this->completion->transitionReturn(SalesReturn::where('company_id', $this->context->id())->whereKey($id)->firstOrFail(), (string) $request->route('action'), $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null)))->resolve());
    }

    public function adjustments(Request $request)
    {
        [$items, $meta] = $this->completion->adjustments($this->context->get(), $request);

        return ApiResponse::success(SalesAdjustmentResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeAdjustment(SalesAdjustmentRequest $request)
    {
        return $this->idempotency->run($request, 'sales.adjustment.create', $this->context->id(), fn () => ApiResponse::success((new SalesAdjustmentResource($this->completion->createAdjustment($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function showAdjustment(string $id)
    {
        $adjustment = SalesAdjustment::where('company_id', $this->context->id())->with(['sale', 'customer', 'currency', 'lines', 'statusHistory'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new SalesAdjustmentResource($adjustment))->resolve());
    }

    public function adjustmentAction(SalesCorrectionActionRequest $request, string $id)
    {
        return ApiResponse::success((new SalesAdjustmentResource($this->completion->transitionAdjustment(SalesAdjustment::where('company_id', $this->context->id())->whereKey($id)->firstOrFail(), (string) $request->route('action'), $this->context->get(), $request, $request->validated()['reason'] ?? null, $request->validated()['version'] ?? null)))->resolve());
    }

    public function receivables(Request $request)
    {
        [$items, $meta] = $this->service->receivables($this->context->get(), $request);

        return ApiResponse::success(ReceivableResource::collection($items)->resolve(), 200, $meta);
    }

    public function aging(Request $request)
    {
        return ApiResponse::success($this->service->aging($this->context->get(), $request));
    }

    public function report(Request $request, string $report)
    {
        return ApiResponse::success($this->service->report($report, $this->context->get(), $request));
    }

    public function receivable(string $id)
    {
        $item = ReceivableOpenItem::where('company_id', $this->context->id())->with(['customer', 'sourceSale', 'currency'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new ReceivableResource($item))->resolve());
    }

    public function statements(Request $request)
    {
        [$items, $meta] = $this->service->billingStatements($this->context->get(), $request);

        return ApiResponse::success(BillingStatementResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeStatement(BillingStatementRequest $request)
    {
        return $this->idempotency->run($request, 'sales.billing_statement.create', $this->context->id(), fn () => ApiResponse::success((new BillingStatementResource($this->service->createBillingStatement($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function statement(string $id)
    {
        $statement = BillingStatement::where('company_id', $this->context->id())->with(['customer', 'currency', 'openItems.customer', 'openItems.sourceSale', 'openItems.currency'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new BillingStatementResource($statement))->resolve());
    }

    private function sale(string $id, array $with = []): Sale
    {
        return Sale::where('company_id', $this->context->id())->with($with)->whereKey($id)->firstOrFail();
    }
}
