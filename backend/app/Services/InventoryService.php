<?php

namespace App\Services;

use App\Events\InventoryLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\InventoryReorderRule;
use App\Models\InventoryValuationRecord;
use App\Models\OpeningStockDocument;
use App\Models\OpeningStockLine;
use App\Models\ProductService;
use App\Models\ReasonCode;
use App\Models\Sale;
use App\Models\StockCountItem;
use App\Models\StockIssue;
use App\Models\StockIssueLine;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\StockReceiptLine;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InventoryService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers) {}

    public function summary(Company $company): array
    {
        $balances = InventoryBalance::where('company_id', $company->id)->where('status', 'active');
        $movements = StockMovement::where('company_id', $company->id)->where('status', 'posted');
        $balanceRows = (clone $balances)->get();
        $rules = InventoryReorderRule::where('company_id', $company->id)->where('status', 'active')->get();
        $lowStock = 0;
        $outOfStock = 0;
        foreach ($rules as $rule) {
            $position = InventoryBalance::where('company_id', $company->id)->where('product_service_id', $rule->product_service_id)->where('status', 'active')
                ->when($rule->warehouse_id, fn ($query) => $query->where('warehouse_id', $rule->warehouse_id))
                ->when($rule->stock_location_id, fn ($query) => $query->where('stock_location_id', $rule->stock_location_id))
                ->get()->reduce(fn (string $total, InventoryBalance $balance) => bcadd($total, $balance->available, 6), '0');
            if (bccomp($position, '0', 6) <= 0) {
                $outOfStock++;
            } elseif (bccomp($position, (string) $rule->reorder_point, 6) <= 0) {
                $lowStock++;
            }
        }
        $countVariances = StockCountItem::where('company_id', $company->id)->whereIn('variance_status', ['variance', 'pending'])->where(function ($query) {
            $query->where('variance_quantity', '!=', 0)->orWhereNull('counted_quantity');
        })->count();

        return [
            'stock_on_hand' => $balanceRows->reduce(fn (string $total, InventoryBalance $balance) => bcadd($total, (string) $balance->on_hand, 6), '0'),
            'available' => $balanceRows->reduce(fn (string $total, InventoryBalance $balance) => bcadd($total, $balance->available, 6), '0'),
            'incoming' => $balanceRows->reduce(fn (string $total, InventoryBalance $balance) => bcadd($total, (string) $balance->incoming, 6), '0'),
            'inventory_value' => null,
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'count_variances' => $countVariances,
            'balance_count' => $balanceRows->count(),
            'recent_movements' => $movements->with(['productService', 'warehouse', 'stockLocation'])->latest('posted_at')->limit(8)->get(),
        ];
    }

    public function lookups(Company $company): array
    {
        return [
            'products' => ProductService::where('company_id', $company->id)->where('status', 'active')->where('stock_managed', true)->with('baseUnit')->orderBy('name')->get(['id', 'code', 'name', 'base_unit_id', 'non_stock']),
            'warehouses' => Warehouse::where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name', 'branch_id']),
            'stock_locations' => StockLocation::where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get(['id', 'warehouse_id', 'code', 'name', 'location_type']),
            'reason_codes' => ReasonCode::where('company_id', $company->id)->where('status', 'active')->where('domain', 'inventory')->orderBy('name')->get(['id', 'code', 'name', 'requires_explanation', 'requires_evidence']),
        ];
    }

    public function balances(Company $company, Request $request): array
    {
        $query = InventoryBalance::where('company_id', $company->id)->where('status', 'active')->with(['productService', 'warehouse', 'stockLocation', 'unitOfMeasure']);
        $query->when($request->filled('product_id'), fn ($q) => $q->where('product_service_id', $request->string('product_id')));
        $query->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->string('warehouse_id')));
        $query->when($request->filled('stock_location_id'), fn ($q) => $q->where('stock_location_id', $request->string('stock_location_id')));
        $query->when($request->boolean('stock_managed_only', true), fn ($q) => $q->whereHas('productService', fn ($product) => $product->where('stock_managed', true)));
        $query->when($request->filled('q'), fn ($q) => $q->whereHas('productService', fn ($product) => $product->where(fn ($search) => $search->where('name', 'ilike', '%'.$request->string('q').'%')->orWhere('code', 'ilike', '%'.$request->string('q').'%'))));
        $page = $query->orderBy('product_service_id')->orderBy('warehouse_id')->orderBy('stock_location_id')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]];
    }

    public function movements(Company $company, Request $request): array
    {
        $query = StockMovement::where('company_id', $company->id)->with(['productService', 'warehouse', 'stockLocation', 'originalMovement', 'reversalMovement']);
        $query->when($request->filled('product_id'), fn ($q) => $q->where('product_service_id', $request->string('product_id')));
        $query->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->string('warehouse_id')));
        $query->when($request->filled('movement_type'), fn ($q) => $q->where('movement_type', $request->string('movement_type')));
        $query->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('source_document_number', 'ilike', '%'.$request->string('q').'%')->orWhere('product_name_snapshot', 'ilike', '%'.$request->string('q').'%')));
        $page = $query->latest('posted_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]];
    }

    public function availability(Company $company, string $productId, Request $request): array
    {
        $query = InventoryBalance::where('company_id', $company->id)->where('product_service_id', $productId)->where('status', 'active');
        $query->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->string('warehouse_id')));
        $query->when($request->filled('stock_location_id'), fn ($q) => $q->where('stock_location_id', $request->string('stock_location_id')));
        $balances = $query->with(['warehouse', 'stockLocation', 'unitOfMeasure'])->get();

        return ['product_id' => $productId, 'positions' => $balances, 'on_hand' => $balances->reduce(fn (string $total, InventoryBalance $balance) => bcadd($total, (string) $balance->on_hand, 6), '0'), 'available' => $balances->reduce(fn (string $total, InventoryBalance $balance) => bcadd($total, $balance->available, 6), '0')];
    }

    public function createOpening(array $input, Company $company, Request $request): OpeningStockDocument
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $document = OpeningStockDocument::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_number' => $this->numbers->next($company->id, 'opening_stock'), 'business_date' => $input['business_date'], 'source_reference' => $input['source_reference'] ?? null, 'explanation' => $input['explanation'], 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($input['lines'] as $lineInput) {
                $line = $this->validatedLine($company, $lineInput);
                OpeningStockLine::create(['id' => (string) Str::uuid(), 'opening_stock_document_id' => $document->id, 'company_id' => $company->id, ...$this->snapshot($lineInput, $line), 'quantity' => $lineInput['quantity']]);
            }
            $this->audit($request, 'inventory.opening_stock.created', $document, [], $document->toArray(), 'Opening Stock was drafted.');
            InventoryLifecycleEvent::dispatch('EVT-INV-001', $company->id, 'opening_stock', $document->id, $request->user()?->id, $request->attributes->get('correlation_id'));

            return $document->fresh('lines');
        });
    }

    public function createReceipt(array $input, Company $company, Request $request): StockReceipt
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $document = StockReceipt::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_number' => $this->numbers->next($company->id, 'stock_receipt'), 'source_type' => $input['source_type'] ?? 'direct', 'source_reference' => $input['source_reference'] ?? null, 'business_date' => $input['business_date'], 'reason_code_id' => $input['reason_code_id'] ?? null, 'explanation' => $input['explanation'], 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($input['lines'] as $lineInput) {
                $line = $this->validatedLine($company, $lineInput);
                StockReceiptLine::create(['id' => (string) Str::uuid(), 'stock_receipt_id' => $document->id, 'company_id' => $company->id, ...$this->snapshot($lineInput, $line), 'quantity' => $lineInput['quantity']]);
            }
            $this->audit($request, 'inventory.receipt.created', $document, [], $document->toArray(), 'A Stock Receipt was drafted.');
            InventoryLifecycleEvent::dispatch('EVT-INV-002', $company->id, 'stock_receipt', $document->id, $request->user()?->id, $request->attributes->get('correlation_id'));

            return $document->fresh('lines');
        });
    }

    public function createIssue(array $input, Company $company, Request $request): StockIssue
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $document = StockIssue::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_number' => $this->numbers->next($company->id, 'stock_issue'), 'source_type' => $input['source_type'] ?? 'direct', 'source_reference' => $input['source_reference'] ?? null, 'business_date' => $input['business_date'], 'reason_code_id' => $input['reason_code_id'] ?? null, 'explanation' => $input['explanation'], 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($input['lines'] as $lineInput) {
                $line = $this->validatedLine($company, $lineInput);
                StockIssueLine::create(['id' => (string) Str::uuid(), 'stock_issue_id' => $document->id, 'company_id' => $company->id, ...$this->snapshot($lineInput, $line), 'quantity' => $lineInput['quantity']]);
            }
            $this->audit($request, 'inventory.issue.created', $document, [], $document->toArray(), 'A Stock Issue was drafted.');
            InventoryLifecycleEvent::dispatch('EVT-INV-004', $company->id, 'stock_issue', $document->id, $request->user()?->id, $request->attributes->get('correlation_id'));

            return $document->fresh('lines');
        });
    }

    public function createTransfer(array $input, Company $company, Request $request): StockTransfer
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $this->validateLocationPair($company, $input['source_warehouse_id'], $input['source_stock_location_id']);
            $this->validateLocationPair($company, $input['destination_warehouse_id'], $input['destination_stock_location_id']);
            if ($input['source_stock_location_id'] === $input['destination_stock_location_id']) {
                throw new RegistryConflictException('A Stock Transfer must use different source and destination Stock Locations.');
            }
            $document = StockTransfer::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_number' => $this->numbers->next($company->id, 'stock_transfer'), 'source_warehouse_id' => $input['source_warehouse_id'], 'source_stock_location_id' => $input['source_stock_location_id'], 'destination_warehouse_id' => $input['destination_warehouse_id'], 'destination_stock_location_id' => $input['destination_stock_location_id'], 'business_date' => $input['business_date'], 'reason_code_id' => $input['reason_code_id'] ?? null, 'explanation' => $input['explanation'], 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($input['lines'] as $lineInput) {
                $line = $this->validatedLine($company, [...$lineInput, 'warehouse_id' => $input['source_warehouse_id'], 'stock_location_id' => $input['source_stock_location_id']]);
                StockTransferLine::create(['id' => (string) Str::uuid(), 'stock_transfer_id' => $document->id, 'company_id' => $company->id, 'product_service_id' => $line->id, 'unit_of_measure_id' => $line->base_unit_id, 'quantity' => $lineInput['quantity'], 'product_code_snapshot' => $line->code, 'product_name_snapshot' => $line->name, 'unit_code_snapshot' => $line->baseUnit?->code ?? 'BASE', 'unit_name_snapshot' => $line->baseUnit?->name ?? 'Base unit']);
            }
            $this->audit($request, 'inventory.transfer.created', $document, [], $document->toArray(), 'A Stock Transfer was drafted.');
            InventoryLifecycleEvent::dispatch('EVT-INV-006', $company->id, 'stock_transfer', $document->id, $request->user()?->id, $request->attributes->get('correlation_id'));

            return $document->fresh('lines');
        });
    }

    public function updateOpening(OpeningStockDocument $document, array $input, Company $company, Request $request): OpeningStockDocument
    {
        return DB::transaction(function () use ($document, $input, $company, $request) {
            $document = OpeningStockDocument::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($document->id);
            $this->assertDraft($document->status, 'Opening Stock');
            $this->assertVersion($document, $input);
            $before = $document->toArray();
            $document->business_date = $input['business_date'];
            $document->source_reference = $input['source_reference'] ?? null;
            $document->explanation = $input['explanation'];
            $document->version++;
            $document->save();
            $this->replaceOpeningLines($document, $input['lines'], $company);
            $this->audit($request, 'inventory.opening_stock.updated', $document, $before, $document->fresh()->toArray(), 'Opening Stock draft was updated.');

            return $document->fresh('lines');
        });
    }

    public function updateReceipt(StockReceipt $document, array $input, Company $company, Request $request): StockReceipt
    {
        return DB::transaction(function () use ($document, $input, $company, $request) {
            $document = StockReceipt::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($document->id);
            $this->assertDraft($document->status, 'Stock Receipt');
            $this->assertVersion($document, $input);
            $before = $document->toArray();
            $document->source_type = $input['source_type'] ?? 'direct';
            $document->source_reference = $input['source_reference'] ?? null;
            $document->business_date = $input['business_date'];
            $document->reason_code_id = $input['reason_code_id'] ?? null;
            $document->explanation = $input['explanation'];
            $document->version++;
            $document->save();
            $this->replaceReceiptLines($document, $input['lines'], $company);
            $this->audit($request, 'inventory.receipt.updated', $document, $before, $document->fresh()->toArray(), 'Stock Receipt draft was updated.');

            return $document->fresh('lines');
        });
    }

    public function updateIssue(StockIssue $document, array $input, Company $company, Request $request): StockIssue
    {
        return DB::transaction(function () use ($document, $input, $company, $request) {
            $document = StockIssue::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($document->id);
            $this->assertDraft($document->status, 'Stock Issue');
            $this->assertVersion($document, $input);
            $before = $document->toArray();
            $document->source_type = $input['source_type'] ?? 'direct';
            $document->source_reference = $input['source_reference'] ?? null;
            $document->business_date = $input['business_date'];
            $document->reason_code_id = $input['reason_code_id'] ?? null;
            $document->explanation = $input['explanation'];
            $document->version++;
            $document->save();
            $this->replaceIssueLines($document, $input['lines'], $company);
            $this->audit($request, 'inventory.issue.updated', $document, $before, $document->fresh()->toArray(), 'Stock Issue draft was updated.');

            return $document->fresh('lines');
        });
    }

    public function updateTransfer(StockTransfer $document, array $input, Company $company, Request $request): StockTransfer
    {
        return DB::transaction(function () use ($document, $input, $company, $request) {
            $document = StockTransfer::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($document->id);
            $this->assertDraft($document->status, 'Stock Transfer');
            $this->assertVersion($document, $input);
            $this->validateLocationPair($company, $input['source_warehouse_id'], $input['source_stock_location_id']);
            $this->validateLocationPair($company, $input['destination_warehouse_id'], $input['destination_stock_location_id']);
            if ($input['source_stock_location_id'] === $input['destination_stock_location_id']) {
                throw new RegistryConflictException('A Stock Transfer must use different source and destination Stock Locations.');
            }
            $before = $document->toArray();
            $document->source_warehouse_id = $input['source_warehouse_id'];
            $document->source_stock_location_id = $input['source_stock_location_id'];
            $document->destination_warehouse_id = $input['destination_warehouse_id'];
            $document->destination_stock_location_id = $input['destination_stock_location_id'];
            $document->business_date = $input['business_date'];
            $document->reason_code_id = $input['reason_code_id'] ?? null;
            $document->explanation = $input['explanation'];
            $document->version++;
            $document->save();
            $this->replaceTransferLines($document, $input['lines'], $company);
            $this->audit($request, 'inventory.transfer.updated', $document, $before, $document->fresh()->toArray(), 'Stock Transfer draft was updated.');

            return $document->fresh('lines');
        });
    }

    public function postOpening(OpeningStockDocument $document, Company $company, Request $request): OpeningStockDocument
    {
        return DB::transaction(function () use ($document, $company, $request) {
            $document = OpeningStockDocument::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($document->id);
            if ($document->status === 'posted') {
                return $document;
            }
            $this->assertDraft($document->status, 'Opening Stock');
            foreach ($document->lines as $line) {
                $movement = $this->postMovement($company, $line->product_service_id, $line->warehouse_id, $line->stock_location_id, $line->unit_of_measure_id, 'opening_stock', 'in', $line->quantity, $document->business_date, 'inventory', OpeningStockDocument::class, $document->id, $line->id, $document->document_number, $document->explanation, null, $request);
                $line->movement_id = $movement->id;
                $line->save();
            }
            $document->status = 'posted';
            $document->posted_by = $request->user()?->id;
            $document->posted_at = now();
            $document->version++;
            $document->save();
            $this->audit($request, 'inventory.opening_stock.posted', $document, [], $document->toArray(), 'Opening Stock was posted.');
            InventoryLifecycleEvent::dispatch('EVT-INV-001', $company->id, 'opening_stock', $document->id, $request->user()?->id, $request->attributes->get('correlation_id'));

            return $document->fresh('lines');
        });
    }

    public function postReceipt(StockReceipt $document, Company $company, Request $request): StockReceipt
    {
        return DB::transaction(function () use ($document, $company, $request) {
            $document = StockReceipt::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($document->id);
            if ($document->status === 'posted') {
                return $document;
            }
            $this->assertDraft($document->status, 'Stock Receipt');
            foreach ($document->lines as $line) {
                $movement = $this->postMovement($company, $line->product_service_id, $line->warehouse_id, $line->stock_location_id, $line->unit_of_measure_id, 'direct_receipt', 'in', $line->quantity, $document->business_date, 'inventory', StockReceipt::class, $document->id, $line->id, $document->document_number, $document->explanation, $document->reason_code_id, $request);
                $line->movement_id = $movement->id;
                $line->save();
            }
            $document->status = 'posted';
            $document->posted_by = $request->user()?->id;
            $document->posted_at = now();
            $document->version++;
            $document->save();
            $this->audit($request, 'inventory.receipt.posted', $document, [], $document->toArray(), 'Stock Receipt was posted.');
            InventoryLifecycleEvent::dispatch('EVT-INV-003', $company->id, 'stock_receipt', $document->id, $request->user()?->id, $request->attributes->get('correlation_id'));

            return $document->fresh('lines');
        });
    }

    /**
     * Post the accepted stock portion of a Purchases Goods Receipt.
     * The source identity remains the Purchases document so Stock Card and
     * audit consumers can distinguish supplier receiving from direct stock entry.
     */
    public function postPurchaseReceipt(StockReceipt $document, Company $company, Request $request, string $sourceType, string $sourceId, array $sourceLineIds = []): StockReceipt
    {
        return DB::transaction(function () use ($document, $company, $request, $sourceType, $sourceId, $sourceLineIds) {
            $document = StockReceipt::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($document->id);
            if ($document->status === 'posted') {
                return $document;
            }
            $this->assertDraft($document->status, 'Purchase Stock Receipt');
            foreach ($document->lines as $line) {
                $sourceLineId = $sourceLineIds[$line->id] ?? $line->id;
                $movement = $this->postMovement(
                    $company,
                    $line->product_service_id,
                    $line->warehouse_id,
                    $line->stock_location_id,
                    $line->unit_of_measure_id,
                    'purchase_receipt',
                    'in',
                    (string) $line->quantity,
                    $document->business_date,
                    'purchases',
                    $sourceType,
                    $sourceId,
                    $sourceLineId,
                    $document->document_number,
                    $document->explanation,
                    null,
                    $request,
                    null,
                    $line->unit_cost === null ? null : (string) $line->unit_cost,
                    $line->total_cost === null ? null : (string) $line->total_cost,
                    $line->currency_code,
                    $line->cost_source ?: 'supplier_invoice_or_purchase_order'
                );
                $line->movement_id = $movement->id;
                $line->save();
            }
            $document->status = 'posted';
            $document->posted_by = $request->user()?->id;
            $document->posted_at = now();
            $document->version++;
            $document->save();

            return $document->fresh('lines');
        });
    }

    /**
     * Post a Purchases-owned return through the MDS-600 movement engine.
     * The downstream document is a Stock Issue representation, but its
     * source identity remains the Purchase Return and source_module is
     * purchases so the stock ledger cannot be mistaken for a direct issue.
     */
    public function postPurchaseReturn(StockIssue $document, Company $company, Request $request, string $sourceType, string $sourceId, array $sourceLineIds = [], array $costs = []): StockIssue
    {
        return DB::transaction(function () use ($document, $company, $request, $sourceType, $sourceId, $sourceLineIds, $costs) {
            $document = StockIssue::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($document->id);
            if ($document->status === 'posted') {
                return $document;
            }
            $this->assertDraft($document->status, 'Purchase Return Stock Issue');
            foreach ($document->lines as $line) {
                $sourceLineId = $sourceLineIds[$line->id] ?? $line->id;
                $cost = $costs[$sourceLineId] ?? $costs[$line->id] ?? [];
                $movement = $this->postMovement(
                    $company,
                    $line->product_service_id,
                    $line->warehouse_id,
                    $line->stock_location_id,
                    $line->unit_of_measure_id,
                    'purchase_return',
                    'out',
                    (string) $line->quantity,
                    $document->business_date,
                    'purchases',
                    $sourceType,
                    $sourceId,
                    $sourceLineId,
                    $document->document_number,
                    $document->explanation,
                    $document->reason_code_id,
                    $request,
                    null,
                    isset($cost['unit_cost']) ? (string) $cost['unit_cost'] : null,
                    isset($cost['total_cost']) ? (string) $cost['total_cost'] : null,
                    $cost['currency_code'] ?? null,
                    $cost['cost_source'] ?? 'purchase_return'
                );
                $line->movement_id = $movement->id;
                $line->save();
            }
            $document->status = 'posted';
            $document->posted_by = $request->user()?->id;
            $document->posted_at = now();
            $document->version++;
            $document->save();

            return $document->fresh('lines');
        });
    }

    /**
     * Post the stock restoration caused by an MDS-200 Sales Return.
     * Sales owns the commercial return document; this method keeps the
     * resulting on-hand balance and movement identity inside MDS-600.
     */
    public function postSalesReturn(array $lines, Company $company, Request $request, string $sourceType, string $sourceId, string $documentNumber, $businessDate, ?string $reasonCodeId = null): array
    {
        return DB::transaction(function () use ($lines, $company, $request, $sourceType, $sourceId, $documentNumber, $businessDate, $reasonCodeId) {
            $movements = [];
            foreach ($lines as $line) {
                if (! $line->stock_managed_snapshot) {
                    continue;
                }
                $movement = $this->postMovement(
                    $company,
                    $line->product_service_id,
                    $line->warehouse_id,
                    $line->stock_location_id,
                    $line->unit_of_measure_id,
                    'sales_return',
                    'in',
                    (string) $line->quantity,
                    $businessDate,
                    'sales',
                    $sourceType,
                    $sourceId,
                    $line->id,
                    $documentNumber,
                    'Stock restoration for Sales Return '.$documentNumber,
                    $reasonCodeId,
                    $request,
                    null,
                    null,
                    null,
                    null,
                    'sales_return'
                );
                $movements[$line->id] = $movement;
            }

            return $movements;
        });
    }

    /**
     * Refresh the MDS-600 incoming projection from approved open purchase orders.
     * This never changes on-hand stock or creates a stock movement.
     */
    public function syncPurchaseIncoming(Company $company, array $positions): void
    {
        DB::transaction(function () use ($company, $positions) {
            InventoryBalance::where('company_id', $company->id)->where('status', 'active')->update(['incoming' => 0, 'updated_at' => now()]);
            foreach ($positions as $position) {
                $quantity = (string) ($position['quantity'] ?? '0');
                if (bccomp($quantity, '0', 6) <= 0) {
                    continue;
                }
                $balance = InventoryBalance::firstOrCreate([
                    'company_id' => $company->id,
                    'product_service_id' => $position['product_service_id'],
                    'warehouse_id' => $position['warehouse_id'],
                    'stock_location_id' => $position['stock_location_id'],
                    'unit_of_measure_id' => $position['unit_of_measure_id'],
                ], [
                    'on_hand' => 0, 'reserved' => 0, 'incoming' => 0, 'outgoing' => 0, 'in_transit' => 0, 'held' => 0, 'count_frozen' => 0, 'status' => 'active', 'version' => 1,
                ]);
                $balance->incoming = bcadd((string) $balance->incoming, $quantity, 6);
                $balance->version++;
                $balance->save();
            }
        });
    }

    public function postIssue(StockIssue $document, Company $company, Request $request): StockIssue
    {
        return DB::transaction(function () use ($document, $company, $request) {
            $document = StockIssue::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($document->id);
            if ($document->status === 'posted') {
                return $document;
            }
            $this->assertDraft($document->status, 'Stock Issue');
            foreach ($document->lines as $line) {
                $movement = $this->postMovement($company, $line->product_service_id, $line->warehouse_id, $line->stock_location_id, $line->unit_of_measure_id, 'direct_issue', 'out', $line->quantity, $document->business_date, 'inventory', StockIssue::class, $document->id, $line->id, $document->document_number, $document->explanation, $document->reason_code_id, $request);
                $line->movement_id = $movement->id;
                $line->save();
            }
            $document->status = 'posted';
            $document->posted_by = $request->user()?->id;
            $document->posted_at = now();
            $document->version++;
            $document->save();
            $this->audit($request, 'inventory.issue.posted', $document, [], $document->toArray(), 'Stock Issue was posted.');
            InventoryLifecycleEvent::dispatch('EVT-INV-005', $company->id, 'stock_issue', $document->id, $request->user()?->id, $request->attributes->get('correlation_id'));

            return $document->fresh('lines');
        });
    }

    public function postTransfer(StockTransfer $document, Company $company, Request $request): StockTransfer
    {
        return DB::transaction(function () use ($document, $company, $request) {
            $document = StockTransfer::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($document->id);
            if ($document->status === 'posted') {
                return $document;
            }
            $this->assertDraft($document->status, 'Stock Transfer');
            if ($document->source_stock_location_id === $document->destination_stock_location_id) {
                throw new RegistryConflictException('A Stock Transfer must use different source and destination Stock Locations.');
            }
            foreach ($document->lines as $line) {
                $this->validatedLine($company, ['product_service_id' => $line->product_service_id, 'warehouse_id' => $document->source_warehouse_id, 'stock_location_id' => $document->source_stock_location_id, 'quantity' => $line->quantity]);
                $source = $this->postMovement($company, $line->product_service_id, $document->source_warehouse_id, $document->source_stock_location_id, $line->unit_of_measure_id, 'transfer_out', 'out', $line->quantity, $document->business_date, 'inventory', StockTransfer::class, $document->id, $line->id, $document->document_number, $document->explanation, $document->reason_code_id, $request, (string) $document->id);
                $destination = $this->postMovement($company, $line->product_service_id, $document->destination_warehouse_id, $document->destination_stock_location_id, $line->unit_of_measure_id, 'transfer_in', 'in', $line->quantity, $document->business_date, 'inventory', StockTransfer::class, $document->id, $line->id, $document->document_number, $document->explanation, $document->reason_code_id, $request, (string) $document->id);
                $line->source_movement_id = $source->id;
                $line->destination_movement_id = $destination->id;
                $line->save();
            }
            $document->status = 'posted';
            $document->posted_by = $request->user()?->id;
            $document->posted_at = now();
            $document->version++;
            $document->save();
            $this->audit($request, 'inventory.transfer.posted', $document, [], $document->toArray(), 'Stock Transfer was posted atomically.');
            InventoryLifecycleEvent::dispatch('EVT-INV-007', $company->id, 'stock_transfer', $document->id, $request->user()?->id, $request->attributes->get('correlation_id'));

            return $document->fresh('lines');
        });
    }

    public function reverseDocument(string $type, string $id, Company $company, Request $request): mixed
    {
        return DB::transaction(function () use ($type, $id, $company, $request) {
            $class = match ($type) {
                'receipt' => StockReceipt::class, 'issue' => StockIssue::class, 'opening_stock' => OpeningStockDocument::class, 'transfer' => StockTransfer::class, default => throw new RegistryConflictException('Unsupported Inventory reversal.')
            };
            $document = $class::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($id);
            if ($document->status === 'reversed') {
                return $document;
            }
            if ($document->status !== 'posted') {
                throw new RegistryConflictException('Only Posted Inventory documents can be reversed.');
            }
            $lines = $document->lines;
            foreach ($lines as $line) {
                $movementIds = match ($type) {
                    'receipt', 'issue', 'opening_stock' => [$line->movement_id], 'transfer' => [$line->source_movement_id, $line->destination_movement_id]
                };
                foreach (array_filter($movementIds) as $movementId) {
                    $this->reverseMovement(StockMovement::where('company_id', $company->id)->findOrFail($movementId), $company, $request);
                }
            }
            $document->status = 'reversed';
            $document->version++;
            $document->save();
            $this->audit($request, 'inventory.'.$type.'.reversed', $document, [], $document->toArray(), 'A posted Inventory document was reversed through linked counter-movements.');
            InventoryLifecycleEvent::dispatch('EVT-INV-021', $company->id, $type, $document->id, $request->user()?->id, $request->attributes->get('correlation_id'));

            return $document->fresh('lines');
        });
    }

    public function postSaleIssues(Sale $sale, Company $company, Request $request): void
    {
        foreach ($sale->lines->where('stock_managed_snapshot', true) as $line) {
            if (! $line->warehouse_id || ! $line->stock_location_id) {
                throw new RegistryConflictException('A stock-managed Sale line requires a Warehouse and Stock Location.', ['dependency' => 'inventory_location']);
            }
            $this->postMovement($company, $line->product_service_id, $line->warehouse_id, $line->stock_location_id, $this->productUnit($company, $line->product_service_id)->id, 'sale_issue', 'out', $line->quantity, $sale->sale_date, 'sales', Sale::class, $sale->id, $line->id, $sale->sale_number, 'Stock issued for Sale '.$sale->sale_number, null, $request);
        }
    }

    public function validateSaleLineLocation(Company $company, ProductService $product, array $line): void
    {
        if (! $product->stock_managed) {
            return;
        }
        if (empty($line['warehouse_id']) || empty($line['stock_location_id'])) {
            throw new RegistryConflictException('Stock-managed Sale lines require a Warehouse and Stock Location.', ['dependency' => 'inventory_location']);
        }
        $this->validateLocationPair($company, $line['warehouse_id'], $line['stock_location_id']);
    }

    public function postAdjustmentMovement(Company $company, string $productId, string $warehouseId, string $locationId, string $unitId, string $direction, string $quantity, $date, string $sourceType, string $sourceId, ?string $sourceLineId, ?string $documentNumber, ?string $explanation, ?string $reasonCodeId, Request $request, ?string $unitCost = null, ?string $totalCost = null, ?string $currencyCode = null, ?string $costSource = null): StockMovement
    {
        return $this->postMovement($company, $productId, $warehouseId, $locationId, $unitId, 'adjustment', $direction, $quantity, $date, 'inventory', $sourceType, $sourceId, $sourceLineId, $documentNumber, $explanation, $reasonCodeId, $request, null, $unitCost, $totalCost, $currencyCode, $costSource);
    }

    public function reverseStockMovement(StockMovement $movement, Company $company, Request $request): StockMovement
    {
        return $this->reverseMovement($movement, $company, $request);
    }

    private function postMovement(Company $company, string $productId, string $warehouseId, string $locationId, string $unitId, string $movementType, string $direction, string $quantity, $date, string $sourceModule, string $sourceType, string $sourceId, ?string $sourceLineId, ?string $documentNumber, ?string $explanation, ?string $reasonCodeId, Request $request, ?string $transferId = null, ?string $unitCost = null, ?string $totalCost = null, ?string $currencyCode = null, ?string $costSource = null): StockMovement
    {
        $existing = StockMovement::where('company_id', $company->id)->where('source_module', $sourceModule)->where('source_type', $sourceType)->where('source_id', $sourceId)->where('source_line_id', $sourceLineId)->where('movement_type', $movementType)->first();
        if ($existing) {
            return $existing;
        }
        $product = ProductService::where('company_id', $company->id)->whereKey($productId)->where('status', 'active')->where('stock_managed', true)->with('baseUnit')->first();
        if (! $product) {
            throw new RegistryConflictException('Only active same-company stock-managed Products may create Inventory movements.', ['dependency' => 'product_service']);
        }
        $this->validateLocationPair($company, $warehouseId, $locationId);
        if ((string) $product->base_unit_id !== (string) $unitId) {
            throw new RegistryConflictException('Inventory movement quantity must use the Product base Unit of Measure.', ['dependency' => 'unit_of_measure']);
        }
        $quantity = (string) $quantity;
        if (bccomp($quantity, '0', 6) <= 0) {
            throw new RegistryConflictException('Inventory movement quantity must be positive.');
        }
        $balance = $this->balanceForUpdate($company, $product, $warehouseId, $locationId, $unitId);
        if ($direction === 'out' && bccomp($balance->available, $quantity, 6) < 0) {
            throw new RegistryConflictException('Insufficient available stock for this Inventory movement.', ['available' => $balance->available, 'requested' => $quantity, 'dependency' => 'inventory_availability']);
        }
        if ($unitCost !== null && ($currencyCode === null || $costSource === null)) {
            throw new RegistryConflictException('A source currency and cost source are required when valuation cost is supplied.', ['dependency' => 'inventory_cost_source']);
        }
        $totalCost ??= $unitCost === null ? null : bcmul((string) $unitCost, $quantity, 6);
        $movement = StockMovement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'product_service_id' => $product->id, 'warehouse_id' => $warehouseId, 'stock_location_id' => $locationId, 'unit_of_measure_id' => $unitId, 'movement_type' => $movementType, 'direction' => $direction, 'quantity' => $quantity, 'business_date' => $date, 'posted_at' => now(), 'source_module' => $sourceModule, 'source_type' => $sourceType, 'source_id' => $sourceId, 'source_line_id' => $sourceLineId, 'source_document_number' => $documentNumber, 'product_code_snapshot' => $product->code, 'product_name_snapshot' => $product->name, 'unit_code_snapshot' => $product->baseUnit?->code ?? 'BASE', 'unit_name_snapshot' => $product->baseUnit?->name ?? 'Base unit', 'reason_code_id' => $reasonCodeId, 'explanation' => $explanation, 'transfer_id' => $transferId, 'unit_cost' => $unitCost, 'total_cost' => $totalCost, 'currency_code' => $currencyCode, 'cost_source' => $costSource, 'status' => 'posted', 'posted_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key') ? $request->header('Idempotency-Key').':'.$sourceLineId.':'.$movementType : null]);
        if ($unitCost !== null) {
            InventoryValuationRecord::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'stock_movement_id' => $movement->id, 'product_service_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'total_cost' => $totalCost, 'currency_code' => $currencyCode, 'cost_source' => $costSource, 'effective_at' => now(), 'created_by' => $request->user()?->id]);
        }
        $balance->on_hand = $direction === 'in' ? bcadd((string) $balance->on_hand, $quantity, 6) : bcsub((string) $balance->on_hand, $quantity, 6);
        $balance->version++;
        $balance->last_movement_at = now();
        $balance->save();

        return $movement;
    }

    private function replaceOpeningLines(OpeningStockDocument $document, array $lines, Company $company): void
    {
        $document->lines()->delete();
        foreach ($lines as $lineInput) {
            $line = $this->validatedLine($company, $lineInput);
            OpeningStockLine::create(['id' => (string) Str::uuid(), 'opening_stock_document_id' => $document->id, 'company_id' => $company->id, ...$this->snapshot($lineInput, $line), 'quantity' => $lineInput['quantity']]);
        }
    }

    private function replaceReceiptLines(StockReceipt $document, array $lines, Company $company): void
    {
        $document->lines()->delete();
        foreach ($lines as $lineInput) {
            $line = $this->validatedLine($company, $lineInput);
            StockReceiptLine::create(['id' => (string) Str::uuid(), 'stock_receipt_id' => $document->id, 'company_id' => $company->id, ...$this->snapshot($lineInput, $line), 'quantity' => $lineInput['quantity']]);
        }
    }

    private function replaceIssueLines(StockIssue $document, array $lines, Company $company): void
    {
        $document->lines()->delete();
        foreach ($lines as $lineInput) {
            $line = $this->validatedLine($company, $lineInput);
            StockIssueLine::create(['id' => (string) Str::uuid(), 'stock_issue_id' => $document->id, 'company_id' => $company->id, ...$this->snapshot($lineInput, $line), 'quantity' => $lineInput['quantity']]);
        }
    }

    private function replaceTransferLines(StockTransfer $document, array $lines, Company $company): void
    {
        $document->lines()->delete();
        foreach ($lines as $lineInput) {
            $line = $this->validatedLine($company, [...$lineInput, 'warehouse_id' => $document->source_warehouse_id, 'stock_location_id' => $document->source_stock_location_id]);
            StockTransferLine::create(['id' => (string) Str::uuid(), 'stock_transfer_id' => $document->id, 'company_id' => $company->id, 'product_service_id' => $line->id, 'unit_of_measure_id' => $line->base_unit_id, 'quantity' => $lineInput['quantity'], 'product_code_snapshot' => $line->code, 'product_name_snapshot' => $line->name, 'unit_code_snapshot' => $line->baseUnit?->code ?? 'BASE', 'unit_name_snapshot' => $line->baseUnit?->name ?? 'Base unit']);
        }
    }

    private function assertVersion($document, array $input): void
    {
        if (array_key_exists('version', $input) && (int) $input['version'] !== (int) $document->version) {
            throw new RegistryConflictException('This Inventory draft was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
    }

    private function reverseMovement(StockMovement $original, Company $company, Request $request): StockMovement
    {
        if ($original->reversal_movement_id) {
            return StockMovement::findOrFail($original->reversal_movement_id);
        }
        $reverseDirection = $original->direction === 'in' ? 'out' : 'in';
        $reversalType = $original->movement_type === 'sale_issue' ? 'sale_reversal' : 'reversal';
        // Transfer documents have two movements for the same source line. Include
        // the original movement id in the reversal identity so both legs remain
        // independently idempotent.
        $reverse = $this->postMovement($company, $original->product_service_id, $original->warehouse_id, $original->stock_location_id, $original->unit_of_measure_id, $reversalType, $reverseDirection, $original->quantity, now()->toDateString(), $original->source_module, $original->source_type.':reversal', $original->source_id, $original->id, $original->source_document_number, 'Reversal of Inventory movement '.$original->id, $original->reason_code_id, $request, $original->transfer_id, $original->unit_cost, $original->total_cost, $original->currency_code, $original->cost_source);
        $original->status = 'reversed';
        $original->reversal_movement_id = $reverse->id;
        $original->save();
        $reverse->original_movement_id = $original->id;
        $reverse->save();

        return $reverse;
    }

    private function balanceForUpdate(Company $company, ProductService $product, string $warehouseId, string $locationId, string $unitId): InventoryBalance
    {
        $balance = InventoryBalance::where('company_id', $company->id)->where('product_service_id', $product->id)->where('warehouse_id', $warehouseId)->where('stock_location_id', $locationId)->where('unit_of_measure_id', $unitId)->lockForUpdate()->first();
        if ($balance) {
            return $balance;
        }
        InventoryBalance::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'product_service_id' => $product->id, 'warehouse_id' => $warehouseId, 'stock_location_id' => $locationId, 'unit_of_measure_id' => $unitId, 'status' => 'active', 'version' => 1]);

        return InventoryBalance::where('company_id', $company->id)->where('product_service_id', $product->id)->where('warehouse_id', $warehouseId)->where('stock_location_id', $locationId)->where('unit_of_measure_id', $unitId)->lockForUpdate()->firstOrFail();
    }

    private function validatedLine(Company $company, array $input): ProductService
    {
        $product = ProductService::where('company_id', $company->id)->whereKey($input['product_service_id'] ?? null)->where('status', 'active')->where('stock_managed', true)->with('baseUnit')->first();
        if (! $product) {
            throw new RegistryConflictException('Every Inventory line must reference an active same-company stock-managed Product.', ['dependency' => 'product_service']);
        }
        $this->validateLocationPair($company, $input['warehouse_id'] ?? '', $input['stock_location_id'] ?? '');
        if ((string) $product->base_unit_id !== (string) ($input['unit_of_measure_id'] ?? $product->base_unit_id)) {
            throw new RegistryConflictException('Inventory quantity must use the Product base Unit of Measure.', ['dependency' => 'unit_of_measure']);
        }
        $quantity = (string) ($input['quantity'] ?? '0');
        if (bccomp($quantity, '0', 6) <= 0) {
            throw new RegistryConflictException('Inventory quantities must be positive.');
        }
        if ($product->baseUnit && ! $product->baseUnit->allows_fractional && bccomp($quantity, (string) (int) $quantity, 6) !== 0) {
            throw new RegistryConflictException('The selected Unit of Measure does not allow fractional quantities.');
        }

        return $product;
    }

    private function snapshot(array $input, ProductService $product): array
    {
        return ['product_service_id' => $product->id, 'warehouse_id' => $input['warehouse_id'], 'stock_location_id' => $input['stock_location_id'], 'unit_of_measure_id' => $product->base_unit_id, 'product_code_snapshot' => $product->code, 'product_name_snapshot' => $product->name, 'unit_code_snapshot' => $product->baseUnit?->code ?? 'BASE', 'unit_name_snapshot' => $product->baseUnit?->name ?? 'Base unit'];
    }

    private function productUnit(Company $company, string $productId): UnitOfMeasure
    {
        $product = ProductService::where('company_id', $company->id)->whereKey($productId)->with('baseUnit')->firstOrFail();

        return $product->baseUnit;
    }

    private function validateLocationPair(Company $company, string $warehouseId, string $locationId): void
    {
        $warehouse = Warehouse::where('company_id', $company->id)->whereKey($warehouseId)->where('status', 'active')->first();
        $location = StockLocation::where('company_id', $company->id)->whereKey($locationId)->where('status', 'active')->first();
        if (! $warehouse || ! $location) {
            throw new RegistryConflictException('The Warehouse and Stock Location must be active and belong to the active company.', ['dependency' => 'location']);
        }
        if ((string) $location->warehouse_id !== (string) $warehouse->id) {
            throw new RegistryConflictException('The Stock Location must belong to the selected Warehouse.', ['dependency' => 'location_warehouse']);
        }
    }

    private function assertDraft(string $status, string $document): void
    {
        if ($status !== 'draft') {
            throw new RegistryConflictException('Only Draft '.$document.' documents can be posted.', ['status' => $status]);
        }
    }

    private function audit(Request $request, string $action, object $record, array $before, array $after, string $description): void
    {
        $this->audit->record($request, $action, $record, $record->company_id, $before, $after, null, 'Inventory', $description);
    }
}
