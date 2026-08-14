<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RegistryConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\InventoryActionRequest;
use App\Http\Requests\Inventory\InventoryAdjustmentRequest;
use App\Http\Requests\Inventory\InventoryDocumentRequest;
use App\Http\Requests\Inventory\InventoryReorderRuleRequest;
use App\Http\Requests\Inventory\OpeningStockRequest;
use App\Http\Requests\Inventory\StockCountEntryRequest;
use App\Http\Requests\Inventory\StockCountRequest;
use App\Http\Requests\Inventory\StockReservationRequest;
use App\Http\Requests\Inventory\StockTransferRequest;
use App\Http\Resources\Inventory\InventoryAdjustmentResource;
use App\Http\Resources\Inventory\InventoryBalanceResource;
use App\Http\Resources\Inventory\InventoryDocumentResource;
use App\Http\Resources\Inventory\InventoryReorderRuleResource;
use App\Http\Resources\Inventory\StockCountResource;
use App\Http\Resources\Inventory\StockMovementResource;
use App\Http\Resources\Inventory\StockReservationResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryReorderRule;
use App\Models\OpeningStockDocument;
use App\Models\StockCount;
use App\Models\StockIssue;
use App\Models\StockReceipt;
use App\Models\StockReservation;
use App\Models\StockTransfer;
use App\Services\InventoryCompletionService;
use App\Services\InventoryService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly InventoryService $service, private readonly InventoryCompletionService $completion, private readonly IdempotencyService $idempotency) {}

    public function summary()
    {
        return ApiResponse::success($this->service->summary($this->context->get()));
    }

    public function lookups()
    {
        return ApiResponse::success($this->service->lookups($this->context->get()));
    }

    public function balances(Request $request)
    {
        [$items, $meta] = $this->service->balances($this->context->get(), $request);

        return ApiResponse::success(InventoryBalanceResource::collection($items)->resolve(), 200, $meta);
    }

    public function movements(Request $request)
    {
        [$items, $meta] = $this->service->movements($this->context->get(), $request);

        return ApiResponse::success(StockMovementResource::collection($items)->resolve(), 200, $meta);
    }

    public function availability(Request $request, string $productId)
    {
        return ApiResponse::success($this->service->availability($this->context->get(), $productId, $request));
    }

    public function storeOpening(OpeningStockRequest $request)
    {
        return $this->run(fn () => $this->idempotency->run($request, 'inventory.opening_stock.create', $this->context->id(), fn () => ApiResponse::success((new InventoryDocumentResource($this->service->createOpening($request->validated(), $this->context->get(), $request)))->resolve(), 201)));
    }

    public function storeReceipt(InventoryDocumentRequest $request)
    {
        return $this->run(fn () => $this->idempotency->run($request, 'inventory.receipt.create', $this->context->id(), fn () => ApiResponse::success((new InventoryDocumentResource($this->service->createReceipt($request->validated(), $this->context->get(), $request)))->resolve(), 201)));
    }

    public function storeIssue(InventoryDocumentRequest $request)
    {
        return $this->run(fn () => $this->idempotency->run($request, 'inventory.issue.create', $this->context->id(), fn () => ApiResponse::success((new InventoryDocumentResource($this->service->createIssue($request->validated(), $this->context->get(), $request)))->resolve(), 201)));
    }

    public function storeTransfer(StockTransferRequest $request)
    {
        return $this->run(fn () => $this->idempotency->run($request, 'inventory.transfer.create', $this->context->id(), fn () => ApiResponse::success((new InventoryDocumentResource($this->service->createTransfer($request->validated(), $this->context->get(), $request)))->resolve(), 201)));
    }

    public function updateDocument(FormRequest $request, string $type, string $id)
    {
        return $this->run(fn () => $this->idempotency->run($request, 'inventory.'.$type.'.update', $this->context->id(), function () use ($request, $type, $id) {
            $company = $this->context->get();
            $input = $request->validated();
            $document = match ($type) {
                'opening-stock' => $this->service->updateOpening(OpeningStockDocument::whereKey($id)->firstOrFail(), $input, $company, $request),
                'receipts' => $this->service->updateReceipt(StockReceipt::whereKey($id)->firstOrFail(), $input, $company, $request),
                'issues' => $this->service->updateIssue(StockIssue::whereKey($id)->firstOrFail(), $input, $company, $request),
                'transfers' => $this->service->updateTransfer(StockTransfer::whereKey($id)->firstOrFail(), $input, $company, $request),
                default => abort(404),
            };

            return ApiResponse::success((new InventoryDocumentResource($document))->resolve());
        }));
    }

    public function showDocument(string $type, string $id)
    {
        $class = match ($type) {
            'opening-stock' => OpeningStockDocument::class, 'receipts' => StockReceipt::class, 'issues' => StockIssue::class, 'transfers' => StockTransfer::class, default => abort(404)
        };
        $document = $class::where('company_id', $this->context->id())->with('lines')->whereKey($id)->firstOrFail();

        return ApiResponse::success((new InventoryDocumentResource($document))->resolve());
    }

    public function postDocument(InventoryActionRequest $request, string $type, string $id)
    {
        return $this->run(fn () => $this->idempotency->run($request, 'inventory.'.$type.'.post', $this->context->id(), function () use ($request, $type, $id) {
            $company = $this->context->get();
            $document = match ($type) {
                'opening-stock' => $this->service->postOpening(OpeningStockDocument::whereKey($id)->firstOrFail(), $company, $request), 'receipts' => $this->service->postReceipt(StockReceipt::whereKey($id)->firstOrFail(), $company, $request), 'issues' => $this->service->postIssue(StockIssue::whereKey($id)->firstOrFail(), $company, $request), 'transfers' => $this->service->postTransfer(StockTransfer::whereKey($id)->firstOrFail(), $company, $request), default => abort(404)
            };

            return ApiResponse::success((new InventoryDocumentResource($document))->resolve());
        }));
    }

    public function reverseDocument(InventoryActionRequest $request, string $type, string $id)
    {
        return $this->run(fn () => $this->idempotency->run($request, 'inventory.'.$type.'.reverse', $this->context->id(), fn () => ApiResponse::success((new InventoryDocumentResource($this->service->reverseDocument(match ($type) {
            'opening-stock' => 'opening_stock', 'receipts' => 'receipt', 'issues' => 'issue', 'transfers' => 'transfer', default => abort(404)
        }, $id, $this->context->get(), $request)))->resolve())));
    }

    private function run(Closure $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (RegistryConflictException $exception) {
            return ApiResponse::error($exception->getMessage(), 409, $exception->errors);
        }
    }

    public function showOpeningStock(string $id)
    {
        return $this->showDocument('opening-stock', $id);
    }

    public function showReceipt(string $id)
    {
        return $this->showDocument('receipts', $id);
    }

    public function showIssue(string $id)
    {
        return $this->showDocument('issues', $id);
    }

    public function showTransfer(string $id)
    {
        return $this->showDocument('transfers', $id);
    }

    public function postOpeningStock(InventoryActionRequest $request, string $id)
    {
        return $this->postDocument($request, 'opening-stock', $id);
    }

    public function postReceipt(InventoryActionRequest $request, string $id)
    {
        return $this->postDocument($request, 'receipts', $id);
    }

    public function postIssue(InventoryActionRequest $request, string $id)
    {
        return $this->postDocument($request, 'issues', $id);
    }

    public function postTransfer(InventoryActionRequest $request, string $id)
    {
        return $this->postDocument($request, 'transfers', $id);
    }

    public function updateOpeningStock(OpeningStockRequest $request, string $id)
    {
        return $this->updateDocument($request, 'opening-stock', $id);
    }

    public function updateReceipt(InventoryDocumentRequest $request, string $id)
    {
        return $this->updateDocument($request, 'receipts', $id);
    }

    public function updateIssue(InventoryDocumentRequest $request, string $id)
    {
        return $this->updateDocument($request, 'issues', $id);
    }

    public function updateTransfer(StockTransferRequest $request, string $id)
    {
        return $this->updateDocument($request, 'transfers', $id);
    }

    public function reverseOpeningStock(InventoryActionRequest $request, string $id)
    {
        return $this->reverseDocument($request, 'opening-stock', $id);
    }

    public function reverseReceipt(InventoryActionRequest $request, string $id)
    {
        return $this->reverseDocument($request, 'receipts', $id);
    }

    public function reverseIssue(InventoryActionRequest $request, string $id)
    {
        return $this->reverseDocument($request, 'issues', $id);
    }

    public function reverseTransfer(InventoryActionRequest $request, string $id)
    {
        return $this->reverseDocument($request, 'transfers', $id);
    }

    public function adjustments(Request $request)
    {
        [$items, $meta] = $this->completion->adjustments($this->context->get(), $request);

        return ApiResponse::success(InventoryAdjustmentResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeAdjustment(InventoryAdjustmentRequest $request)
    {
        return $this->run(fn () => $this->idempotency->run($request, 'inventory.adjustment.create', $this->context->id(), fn () => ApiResponse::success((new InventoryAdjustmentResource($this->completion->createAdjustment($request->validated(), $this->context->get(), $request)))->resolve(), 201)));
    }

    public function showAdjustment(string $id)
    {
        $record = InventoryAdjustment::where('company_id', $this->context->id())->with('lines')->findOrFail($id);

        return ApiResponse::success((new InventoryAdjustmentResource($record))->resolve());
    }

    public function updateAdjustment(InventoryAdjustmentRequest $request, string $id)
    {
        return $this->run(fn () => ApiResponse::success((new InventoryAdjustmentResource($this->completion->updateAdjustment(InventoryAdjustment::where('company_id', $this->context->id())->findOrFail($id), $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function adjustmentAction(InventoryActionRequest $request, string $id)
    {
        return $this->run(fn () => ApiResponse::success((new InventoryAdjustmentResource($this->completion->transitionAdjustment(InventoryAdjustment::where('company_id', $this->context->id())->findOrFail($id), (string) $request->route('action'), $this->context->get(), $request)))->resolve()));
    }

    public function reverseAdjustment(InventoryActionRequest $request, string $id)
    {
        return $this->run(fn () => ApiResponse::success((new InventoryAdjustmentResource($this->completion->reverseAdjustment(InventoryAdjustment::where('company_id', $this->context->id())->findOrFail($id), $this->context->get(), $request)))->resolve()));
    }

    public function counts(Request $request)
    {
        [$items, $meta] = $this->completion->counts($this->context->get(), $request);

        return ApiResponse::success(StockCountResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeCount(StockCountRequest $request)
    {
        return $this->run(fn () => $this->idempotency->run($request, 'inventory.count.create', $this->context->id(), fn () => ApiResponse::success((new StockCountResource($this->completion->createCount($request->validated(), $this->context->get(), $request)))->resolve(), 201)));
    }

    public function showCount(string $id)
    {
        $record = StockCount::where('company_id', $this->context->id())->with('items')->findOrFail($id);

        return ApiResponse::success((new StockCountResource($record))->resolve());
    }

    public function startCount(InventoryActionRequest $request, string $id)
    {
        return $this->run(fn () => ApiResponse::success((new StockCountResource($this->completion->startCount(StockCount::where('company_id', $this->context->id())->findOrFail($id), $this->context->get(), $request)))->resolve()));
    }

    public function countEntry(StockCountEntryRequest $request, string $id)
    {
        return $this->run(fn () => ApiResponse::success((new StockCountResource($this->completion->enterCount(StockCount::where('company_id', $this->context->id())->findOrFail($id), $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function countRecount(StockCountEntryRequest $request, string $id)
    {
        return $this->run(fn () => ApiResponse::success((new StockCountResource($this->completion->enterCount(StockCount::where('company_id', $this->context->id())->findOrFail($id), $request->validated(), $this->context->get(), $request, true)))->resolve()));
    }

    public function countAction(InventoryActionRequest $request, string $id)
    {
        return $this->run(fn () => ApiResponse::success((new StockCountResource($this->completion->transitionCount(StockCount::where('company_id', $this->context->id())->findOrFail($id), (string) $request->route('action'), $this->context->get(), $request)))->resolve()));
    }

    public function reservations(Request $request)
    {
        [$items, $meta] = $this->completion->reservations($this->context->get(), $request);

        return ApiResponse::success(StockReservationResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeReservation(StockReservationRequest $request)
    {
        return $this->run(fn () => $this->idempotency->run($request, 'inventory.reservation.create', $this->context->id(), fn () => ApiResponse::success((new StockReservationResource($this->completion->createReservation($request->validated(), $this->context->get(), $request)))->resolve(), 201)));
    }

    public function showReservation(string $id)
    {
        $record = StockReservation::where('company_id', $this->context->id())->with('productService')->findOrFail($id);

        return ApiResponse::success((new StockReservationResource($record))->resolve());
    }

    public function reservationAction(InventoryActionRequest $request, string $id)
    {
        return $this->run(fn () => ApiResponse::success((new StockReservationResource($this->completion->changeReservation(StockReservation::where('company_id', $this->context->id())->findOrFail($id), (string) $request->route('action'), $this->context->get(), $request)))->resolve()));
    }

    public function reorderRules(Request $request)
    {
        [$items, $meta] = $this->completion->reorderRules($this->context->get(), $request);

        return ApiResponse::success(InventoryReorderRuleResource::collection($items)->resolve(), 200, $meta);
    }

    public function storeReorderRule(InventoryReorderRuleRequest $request)
    {
        return $this->run(fn () => ApiResponse::success((new InventoryReorderRuleResource($this->completion->saveReorderRule(null, $request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function updateReorderRule(InventoryReorderRuleRequest $request, string $id)
    {
        return $this->run(fn () => ApiResponse::success((new InventoryReorderRuleResource($this->completion->saveReorderRule(InventoryReorderRule::where('company_id', $this->context->id())->findOrFail($id), $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function deleteReorderRule(string $id)
    {
        $this->completion->deleteReorderRule(InventoryReorderRule::where('company_id', $this->context->id())->findOrFail($id), $this->context->get());

        return ApiResponse::success(['deleted' => true]);
    }

    public function attention()
    {
        return ApiResponse::success(['items' => $this->completion->conditions($this->context->get())]);
    }

    public function stockCard(Request $request)
    {
        return ApiResponse::success($this->completion->stockCard($this->context->get(), $request));
    }

    public function report(Request $request, string $report)
    {
        return $this->run(fn () => ApiResponse::success($this->completion->report($report, $this->context->get(), $request)));
    }

    public function barcode(string $barcode)
    {
        $product = $this->completion->barcode($this->context->get(), $barcode);
        if (! $product) {
            return ApiResponse::error('No active stock-managed Product was found for this barcode.', 404);
        }

        return ApiResponse::success(['id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'barcode' => $product->barcode, 'base_unit' => ['id' => $product->baseUnit?->id, 'code' => $product->baseUnit?->code, 'name' => $product->baseUnit?->name]]);
    }
}
