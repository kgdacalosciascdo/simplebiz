<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RegistryConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\BillingStatementRequest;
use App\Http\Requests\Sales\SaleActionRequest;
use App\Http\Requests\Sales\StoreSaleRequest;
use App\Http\Requests\Sales\UpdateSaleRequest;
use App\Http\Resources\Sales\BillingStatementResource;
use App\Http\Resources\Sales\ReceivableResource;
use App\Http\Resources\Sales\SaleResource;
use App\Models\BillingStatement;
use App\Models\ReceivableOpenItem;
use App\Models\Sale;
use App\Services\SalesService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly SalesService $service, private readonly IdempotencyService $idempotency, private readonly AuditService $audit) {}

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

    public function store(StoreSaleRequest $request)
    {
        return $this->idempotency->run($request, 'sales.create', $this->context->id(), fn () => ApiResponse::success((new SaleResource($this->service->createDraft($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function show(string $id)
    {
        $sale = $this->sale($id, ['lines', 'customer', 'currency', 'paymentTerm', 'receivable', 'statusHistory']);

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

    public function history(string $id)
    {
        $sale = $this->sale($id);

        return ApiResponse::success($sale->statusHistory()->get());
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
