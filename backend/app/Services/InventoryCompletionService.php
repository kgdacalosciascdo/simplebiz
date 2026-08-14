<?php

namespace App\Services;

use App\Events\InventoryLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\Company;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\InventoryBalance;
use App\Models\InventoryReorderRule;
use App\Models\InventoryValuationRecord;
use App\Models\ProductService;
use App\Models\ReasonCode;
use App\Models\Sale;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\StockCountItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\StockReservationEvent;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InventoryCompletionService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers, private readonly InventoryService $inventory) {}

    public function adjustments(Company $company, Request $request): array
    {
        $query = InventoryAdjustment::where('company_id', $company->id)->with('lines')->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('q'), fn ($q) => $q->where('adjustment_number', 'like', '%'.$request->string('q').'%'))->latest('created_at');
        $page = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function createAdjustment(array $input, Company $company, Request $request): InventoryAdjustment
    {
        $reason = $this->reason($company, $input['reason_code_id'], $input['explanation'], $input['evidence_reference'] ?? null);

        return DB::transaction(function () use ($input, $company, $request, $reason) {
            $adjustment = InventoryAdjustment::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'adjustment_number' => $this->numbers->next($company->id, 'inventory_adjustment'), 'source_type' => $input['source_type'] ?? 'direct', 'source_reference' => $input['source_reference'] ?? null, 'business_date' => $input['business_date'], 'reason_code_id' => $reason->id, 'evidence_reference' => $input['evidence_reference'] ?? null, 'explanation' => $input['explanation'], 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $this->writeAdjustmentLines($adjustment, $input['lines'], $company);
            $this->audit($request, 'inventory.adjustment.created', $adjustment, [], $adjustment->toArray(), 'An Inventory Adjustment was drafted.');
            InventoryLifecycleEvent::dispatch('EVT-INV-ADJUSTMENT-CREATED', $company->id, 'inventory_adjustment', $adjustment->id, $request->user()?->id, $request->attributes->get('correlation_id'));

            return $adjustment->fresh('lines');
        });
    }

    public function updateAdjustment(InventoryAdjustment $record, array $input, Company $company, Request $request): InventoryAdjustment
    {
        $this->reason($company, $input['reason_code_id'], $input['explanation'], $input['evidence_reference'] ?? null);

        return DB::transaction(function () use ($record, $input, $company, $request) {
            $record = InventoryAdjustment::where('company_id', $company->id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($record, $input);
            if ($record->status !== 'draft') {
                throw new RegistryConflictException('Only Draft Inventory Adjustments can be edited.', ['status' => $record->status]);
            }
            $before = $record->toArray();
            $record->source_type = $input['source_type'] ?? 'direct';
            $record->source_reference = $input['source_reference'] ?? null;
            $record->business_date = $input['business_date'];
            $record->reason_code_id = $input['reason_code_id'];
            $record->evidence_reference = $input['evidence_reference'] ?? null;
            $record->explanation = $input['explanation'];
            $record->version++;
            $record->save();
            $record->lines()->delete();
            $this->writeAdjustmentLines($record, $input['lines'], $company);
            $this->audit($request, 'inventory.adjustment.updated', $record, $before, $record->fresh()->toArray(), 'An Inventory Adjustment draft was updated.');

            return $record->fresh('lines');
        });
    }

    public function transitionAdjustment(InventoryAdjustment $record, string $action, Company $company, Request $request): InventoryAdjustment
    {
        return DB::transaction(function () use ($record, $action, $company, $request) {
            $record = InventoryAdjustment::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($record->id);
            $before = $record->toArray();
            $actor = $request->user()?->id;
            if ($action === 'submit') {
                $this->transition($record, 'draft', 'for_approval');
                $record->submitted_by = $actor;
                $record->submitted_at = now();
            } elseif ($action === 'review') {
                $this->transition($record, 'for_approval', 'for_approval');
                $record->reviewed_by = $actor;
                $record->reviewed_at = now();
            } elseif ($action === 'approve') {
                if ($record->status !== 'for_approval') {
                    throw new RegistryConflictException('Only Inventory Adjustments waiting for approval can be approved.');
                } if ($actor && in_array($actor, array_filter([$record->created_by, $record->submitted_by]), true)) {
                    throw new RegistryConflictException('The preparer cannot approve the same Inventory Adjustment.');
                } $record->status = 'approved';
                $record->approved_by = $actor;
                $record->approved_at = now();
            } elseif ($action === 'post') {
                $this->postAdjustment($record, $company, $request);
            } elseif ($action === 'cancel') {
                if (in_array($record->status, ['posted', 'reversed', 'cancelled'], true)) {
                    throw new RegistryConflictException('This Inventory Adjustment cannot be cancelled from its current status.');
                } $record->status = 'cancelled';
            } else {
                throw new RegistryConflictException('Unsupported Inventory Adjustment action.');
            }
            $record->version++;
            $record->save();
            $this->audit($request, 'inventory.adjustment.'.$action, $record, $before, $record->toArray(), 'Inventory Adjustment lifecycle action completed.');
            InventoryLifecycleEvent::dispatch('EVT-INV-ADJUSTMENT-'.strtoupper($action), $company->id, 'inventory_adjustment', $record->id, $actor, $request->attributes->get('correlation_id'));

            return $record->fresh('lines');
        });
    }

    public function reverseAdjustment(InventoryAdjustment $record, Company $company, Request $request): InventoryAdjustment
    {
        return DB::transaction(function () use ($record, $company, $request) {
            $record = InventoryAdjustment::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($record->id);
            if ($record->status === 'reversed') {
                return $record;
            }
            if ($record->status !== 'posted') {
                throw new RegistryConflictException('Only Posted Inventory Adjustments can be reversed.');
            }
            foreach ($record->lines as $line) {
                if ($line->movement_id) {
                    $this->inventory->reverseStockMovement(StockMovement::where('company_id', $company->id)->findOrFail($line->movement_id), $company, $request);
                }
            }
            $record->status = 'reversed';
            $record->reversed_by = $request->user()?->id;
            $record->reversed_at = now();
            $record->version++;
            $record->save();
            $this->audit($request, 'inventory.adjustment.reversed', $record, [], $record->toArray(), 'A posted Inventory Adjustment was reversed.');

            return $record->fresh('lines');
        });
    }

    public function counts(Company $company, Request $request): array
    {
        $query = StockCount::where('company_id', $company->id)->with('items')->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->latest('created_at');
        $page = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function createCount(array $input, Company $company, Request $request): StockCount
    {
        $this->validateLocation($company, $input['warehouse_id'], $input['stock_location_id']);
        $products = ! empty($input['all_products']) ? ProductService::where('company_id', $company->id)->where('status', 'active')->where('stock_managed', true)->with('baseUnit')->orderBy('name')->get() : ProductService::where('company_id', $company->id)->whereIn('id', $input['product_service_ids'] ?? [])->where('status', 'active')->where('stock_managed', true)->with('baseUnit')->get();
        if ($products->isEmpty()) {
            throw new RegistryConflictException('A Physical Count must include at least one active stock-managed Product.');
        }

        return DB::transaction(function () use ($input, $company, $request, $products) {
            $count = StockCount::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'count_number' => $this->numbers->next($company->id, 'stock_count'), 'business_date' => $input['business_date'], 'mode' => $input['mode'], 'warehouse_id' => $input['warehouse_id'], 'stock_location_id' => $input['stock_location_id'], 'blind' => (bool) ($input['blind'] ?? false), 'status' => 'planned', 'evidence_reference' => $input['evidence_reference'] ?? null, 'explanation' => $input['explanation'] ?? null, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($products as $product) {
                $expected = (string) (InventoryBalance::where('company_id', $company->id)->where('product_service_id', $product->id)->where('warehouse_id', $input['warehouse_id'])->where('stock_location_id', $input['stock_location_id'])->where('status', 'active')->value('on_hand') ?? '0');
                StockCountItem::create(['id' => (string) Str::uuid(), 'stock_count_id' => $count->id, 'company_id' => $company->id, 'product_service_id' => $product->id, 'warehouse_id' => $input['warehouse_id'], 'stock_location_id' => $input['stock_location_id'], 'unit_of_measure_id' => $product->base_unit_id, 'expected_quantity' => $expected, 'product_code_snapshot' => $product->code, 'product_name_snapshot' => $product->name, 'unit_code_snapshot' => $product->baseUnit?->code ?? 'BASE', 'unit_name_snapshot' => $product->baseUnit?->name ?? 'Base unit']);
            }
            $this->audit($request, 'inventory.count.created', $count, [], $count->toArray(), 'A Physical Count was planned.');

            return $count->fresh('items');
        });
    }

    public function startCount(StockCount $record, Company $company, Request $request): StockCount
    {
        return DB::transaction(function () use ($record, $company, $request) {
            $record = StockCount::where('company_id', $company->id)->lockForUpdate()->findOrFail($record->id);
            if ($record->status !== 'planned') {
                throw new RegistryConflictException('Only Planned Physical Counts can be started.', ['status' => $record->status]);
            }
            $record->status = 'in_progress';
            $record->snapshot_at = now();
            $record->cutoff_at = now();
            $record->started_by = $request->user()?->id;
            $record->started_at = now();
            $record->version++;
            $record->save();
            $this->audit($request, 'inventory.count.started', $record, [], $record->toArray(), 'A Physical Count was started.');

            return $record->fresh('items');
        });
    }

    public function enterCount(StockCount $record, array $input, Company $company, Request $request, bool $recount = false): StockCount
    {
        return DB::transaction(function () use ($record, $input, $company, $request, $recount) {
            $count = StockCount::where('company_id', $company->id)->with('items')->lockForUpdate()->findOrFail($record->id);
            if (! in_array($count->status, ['in_progress', 'variance_review'], true)) {
                throw new RegistryConflictException('This Physical Count is not accepting count entries.', ['status' => $count->status]);
            }
            $item = StockCountItem::where('company_id', $company->id)->where('stock_count_id', $count->id)->lockForUpdate()->findOrFail($input['item_id']);
            $sequence = ((int) StockCountEntry::where('stock_count_item_id', $item->id)->max('sequence')) + 1;
            $entry = StockCountEntry::create(['id' => (string) Str::uuid(), 'stock_count_id' => $count->id, 'stock_count_item_id' => $item->id, 'company_id' => $company->id, 'sequence' => $sequence, 'is_recount' => $recount || $sequence > 1, 'counted_quantity' => $input['counted_quantity'], 'evidence_reference' => $input['evidence_reference'] ?? null, 'explanation' => $input['explanation'] ?? null, 'counted_by' => $request->user()?->id, 'counted_at' => now()]);
            $item->counted_quantity = $input['counted_quantity'];
            $item->variance_quantity = bcsub((string) $input['counted_quantity'], (string) $item->expected_quantity, 6);
            $item->variance_status = bccomp((string) $item->variance_quantity, '0', 6) === 0 ? 'matched' : 'variance';
            $item->recount_required = bccomp((string) $item->variance_quantity, '0', 6) !== 0 && ! $entry->is_recount;
            $item->latest_entry_id = $entry->id;
            $item->save();
            $count->version++;
            $count->save();

            return $count->fresh('items');
        });
    }

    public function transitionCount(StockCount $record, string $action, Company $company, Request $request): StockCount
    {
        return DB::transaction(function () use ($record, $action, $company, $request) {
            $count = StockCount::where('company_id', $company->id)->with('items')->lockForUpdate()->findOrFail($record->id);
            $actor = $request->user()?->id;
            if ($action === 'submit') {
                if ($count->status !== 'in_progress' || $count->items->contains(fn ($item) => $item->counted_quantity === null)) {
                    throw new RegistryConflictException('All Physical Count items must be counted before submission.');
                } $count->status = 'variance_review';
                $count->submitted_by = $actor;
                $count->submitted_at = now();
            } elseif ($action === 'review') {
                if ($count->status !== 'variance_review') {
                    throw new RegistryConflictException('Only Physical Counts in Variance Review can be reviewed.');
                } $count->status = 'for_approval';
                $count->reviewed_by = $actor;
                $count->reviewed_at = now();
            } elseif ($action === 'approve') {
                if ($count->status !== 'for_approval') {
                    throw new RegistryConflictException('Only Physical Counts waiting for approval can be approved.');
                } $count->status = 'approved';
                $count->approved_by = $actor;
                $count->approved_at = now();
            } elseif ($action === 'post') {
                $this->postCount($count, $company, $request);
            } elseif ($action === 'close') {
                if ($count->status !== 'posted') {
                    throw new RegistryConflictException('Only Posted Physical Counts can be closed.');
                } $count->status = 'closed';
                $count->closed_by = $actor;
                $count->closed_at = now();
            } elseif ($action === 'cancel') {
                if (in_array($count->status, ['posted', 'closed', 'cancelled'], true)) {
                    throw new RegistryConflictException('This Physical Count cannot be cancelled from its current status.');
                } $count->status = 'cancelled';
            } elseif ($action === 'reopen') {
                if ($count->status !== 'closed') {
                    throw new RegistryConflictException('Only Closed Physical Counts can be reopened.');
                } throw new RegistryConflictException('A posted Physical Count cannot be reopened; use a linked reversal or a new count.');
            } else {
                throw new RegistryConflictException('Unsupported Physical Count action.');
            }
            $count->version++;
            $count->save();
            $this->audit($request, 'inventory.count.'.$action, $count, [], $count->toArray(), 'Physical Count lifecycle action completed.');
            InventoryLifecycleEvent::dispatch('EVT-INV-COUNT-'.strtoupper($action), $company->id, 'stock_count', $count->id, $actor, $request->attributes->get('correlation_id'));

            return $count->fresh('items');
        });
    }

    public function reservations(Company $company, Request $request): array
    {
        $query = StockReservation::where('company_id', $company->id)->with('productService')->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('product_id'), fn ($q) => $q->where('product_service_id', $request->string('product_id')))->latest('created_at');
        $page = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function createReservation(array $input, Company $company, Request $request): StockReservation
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $existing = StockReservation::where('company_id', $company->id)->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->where('source_line_id', $input['source_line_id'])->lockForUpdate()->first();
            if ($existing) {
                if (bccomp((string) $existing->quantity, (string) $input['quantity'], 6) !== 0) {
                    throw new RegistryConflictException('A reservation already exists for this source line with a different quantity.');
                }

                return $existing;
            }
            $product = $this->validateProductLine($company, $input);
            $balance = $this->balanceForUpdate($company, $product, $input['warehouse_id'], $input['stock_location_id'], $product->base_unit_id);
            if (bccomp($balance->available, (string) $input['quantity'], 6) < 0) {
                throw new RegistryConflictException('Insufficient available stock for this reservation.', ['available' => $balance->available, 'requested' => $input['quantity']]);
            }
            $reservation = StockReservation::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'reservation_number' => $this->numbers->next($company->id, 'stock_reservation'), 'source_type' => $input['source_type'], 'source_id' => $input['source_id'], 'source_line_id' => $input['source_line_id'], 'source_document_number' => $input['source_document_number'] ?? null, 'product_service_id' => $product->id, 'warehouse_id' => $input['warehouse_id'], 'stock_location_id' => $input['stock_location_id'], 'unit_of_measure_id' => $product->base_unit_id, 'quantity' => $input['quantity'], 'status' => 'active', 'expires_at' => $input['expires_at'] ?? null, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $balance->reserved = bcadd((string) $balance->reserved, (string) $input['quantity'], 6);
            $balance->version++;
            $balance->save();
            $this->reservationEvent($reservation, 'created', (string) $input['quantity'], $request, null);

            return $reservation->fresh('productService');
        });
    }

    public function changeReservation(StockReservation $record, string $action, Company $company, Request $request): StockReservation
    {
        return DB::transaction(function () use ($record, $action, $company, $request) {
            $reservation = StockReservation::where('company_id', $company->id)->lockForUpdate()->findOrFail($record->id);
            $expired = false;
            if ($action === 'expire') {
                if (! $reservation->expires_at || $reservation->expires_at->isFuture()) {
                    throw new RegistryConflictException('Only expired reservations can be expired.');
                }
                $expired = true;
                $action = 'release';
            }
            $remaining = $reservation->remaining;
            if (in_array($action, ['release'], true)) {
                if (! in_array($reservation->status, ['requested', 'active', 'partially_consumed'], true) || bccomp($remaining, '0', 6) <= 0) {
                    return $reservation;
                }
                $balance = $this->balanceForUpdate($company, ProductService::where('company_id', $company->id)->whereKey($reservation->product_service_id)->with('baseUnit')->firstOrFail(), $reservation->warehouse_id, $reservation->stock_location_id, $reservation->unit_of_measure_id);
                $balance->reserved = bcsub((string) $balance->reserved, $remaining, 6);
                $balance->version++;
                $balance->save();
                $reservation->released_quantity = bcadd((string) $reservation->released_quantity, $remaining, 6);
                $reservation->status = $expired ? 'expired' : 'released';
                $this->reservationEvent($reservation, $expired ? 'expired' : 'released', $remaining, $request, $request->input('reason'));
            } elseif ($action === 'consume') {
                if (! in_array($reservation->status, ['requested', 'active', 'partially_consumed'], true)) {
                    throw new RegistryConflictException('This reservation cannot be consumed from its current status.');
                }
                $quantity = (string) ($request->input('quantity') ?: $remaining);
                if (bccomp($quantity, '0', 6) <= 0 || bccomp($quantity, $remaining, 6) > 0) {
                    throw new RegistryConflictException('Consumed quantity must be positive and cannot exceed the reservation remaining quantity.');
                }
                $balance = $this->balanceForUpdate($company, ProductService::where('company_id', $company->id)->whereKey($reservation->product_service_id)->with('baseUnit')->firstOrFail(), $reservation->warehouse_id, $reservation->stock_location_id, $reservation->unit_of_measure_id);
                $balance->reserved = bcsub((string) $balance->reserved, $quantity, 6);
                $balance->version++;
                $balance->save();
                $reservation->consumed_quantity = bcadd((string) $reservation->consumed_quantity, $quantity, 6);
                $reservation->status = bccomp($reservation->remaining, '0', 6) === 0 ? 'consumed' : 'partially_consumed';
                $this->reservationEvent($reservation, 'consumed', $quantity, $request, $request->input('reason'));
            } else {
                throw new RegistryConflictException('Unsupported Stock Reservation action.');
            }
            $reservation->version++;
            $reservation->save();

            return $reservation->fresh('productService');
        });
    }

    public function syncSaleReservations(Sale $sale, Company $company, Request $request): void
    {
        DB::transaction(function () use ($sale, $company, $request) {
            StockReservation::where('company_id', $company->id)->where('source_type', Sale::class)->where('source_id', $sale->id)->whereIn('status', ['requested', 'active', 'partially_consumed'])->get()->each(fn ($reservation) => $this->changeReservation($reservation, 'release', $company, $request));
            foreach ($sale->lines()->get() as $line) {
                if (! $line->stock_managed_snapshot) {
                    continue;
                }
                $this->createReservation(['source_type' => Sale::class, 'source_id' => $sale->id, 'source_line_id' => $line->id, 'source_document_number' => $sale->sale_number, 'product_service_id' => $line->product_service_id, 'warehouse_id' => $line->warehouse_id, 'stock_location_id' => $line->stock_location_id, 'quantity' => (string) $line->quantity], $company, $request);
            }
        });
    }

    public function consumeSaleReservations(Sale $sale, Company $company, Request $request): void
    {
        foreach (StockReservation::where('company_id', $company->id)->where('source_type', Sale::class)->where('source_id', $sale->id)->whereIn('status', ['requested', 'active', 'partially_consumed'])->get() as $reservation) {
            $this->changeReservation($reservation, 'consume', $company, $request);
        }
    }

    public function releaseSaleReservations(Sale $sale, Company $company, Request $request): void
    {
        foreach (StockReservation::where('company_id', $company->id)->where('source_type', Sale::class)->where('source_id', $sale->id)->whereIn('status', ['requested', 'active', 'partially_consumed'])->get() as $reservation) {
            $this->changeReservation($reservation, 'release', $company, $request);
        }
    }

    public function reorderRules(Company $company, Request $request): array
    {
        $query = InventoryReorderRule::where('company_id', $company->id)->with('productService')->latest('created_at');
        $page = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function saveReorderRule(?InventoryReorderRule $record, array $input, Company $company, Request $request): InventoryReorderRule
    {
        $product = ProductService::where('company_id', $company->id)->whereKey($input['product_service_id'])->where('status', 'active')->where('stock_managed', true)->first();
        if (! $product) {
            throw new RegistryConflictException('An active same-company stock-managed Product is required.');
        }
        if ($input['warehouse_id'] ?? null) {
            $this->validateLocation($company, $input['warehouse_id'], $input['stock_location_id'] ?? null);
        }
        if ($input['stock_location_id'] ?? null) {
            $this->validateLocation($company, $input['warehouse_id'] ?? StockLocation::where('company_id', $company->id)->whereKey($input['stock_location_id'])->value('warehouse_id'), $input['stock_location_id']);
        }

        return DB::transaction(function () use ($record, $input, $company) {
            $rule = $record ? InventoryReorderRule::where('company_id', $company->id)->whereKey($record->id)->lockForUpdate()->firstOrFail() : new InventoryReorderRule(['id' => (string) Str::uuid(), 'company_id' => $company->id]);
            $this->assertVersion($rule, $input);
            $rule->fill(['product_service_id' => $input['product_service_id'], 'warehouse_id' => $input['warehouse_id'] ?? null, 'stock_location_id' => $input['stock_location_id'] ?? null, 'reorder_point' => $input['reorder_point'], 'minimum_quantity' => $input['minimum_quantity'] ?? 0, 'target_quantity' => $input['target_quantity'] ?? null, 'suggested_quantity' => $input['suggested_quantity'] ?? null, 'status' => $input['status'] ?? 'active', 'effective_from' => $input['effective_from'] ?? null, 'effective_to' => $input['effective_to'] ?? null]);
            $rule->version = ((int) ($rule->version ?: 0)) + 1;
            $rule->save();

            return $rule->fresh('productService');
        });
    }

    public function deleteReorderRule(InventoryReorderRule $record, Company $company): void
    {
        InventoryReorderRule::where('company_id', $company->id)->whereKey($record->id)->delete();
    }

    public function conditions(Company $company): array
    {
        $items = [];
        foreach (InventoryReorderRule::where('company_id', $company->id)->where('status', 'active')->with('productService')->get() as $rule) {
            $available = $this->availableForRule($company, $rule);
            if (bccomp($available, '0', 6) <= 0 || bccomp($available, (string) $rule->reorder_point, 6) <= 0) {
                $items[] = ['type' => bccomp($available, '0', 6) <= 0 ? 'out_of_stock' : 'low_stock', 'severity' => bccomp($available, '0', 6) <= 0 ? 'high' : 'medium', 'product_id' => $rule->product_service_id, 'product_code' => $rule->productService?->code, 'product_name' => $rule->productService?->name, 'available' => $available, 'reorder_point' => $rule->reorder_point, 'rule_id' => $rule->id];
            }
        }

        return $items;
    }

    public function stockCard(Company $company, Request $request): array
    {
        $query = StockMovement::where('company_id', $company->id)->where('status', 'posted')->when($request->filled('product_id'), fn ($q) => $q->where('product_service_id', $request->string('product_id')))->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->string('warehouse_id')))->when($request->filled('stock_location_id'), fn ($q) => $q->where('stock_location_id', $request->string('stock_location_id')))->when($request->filled('as_of'), fn ($q) => $q->whereDate('business_date', '<=', $request->date('as_of')))->orderBy('business_date')->orderBy('posted_at')->orderBy('id');
        $running = '0';
        $items = [];
        foreach ($query->with(['productService', 'warehouse', 'stockLocation'])->get() as $movement) {
            $running = $movement->direction === 'in' ? bcadd($running, (string) $movement->quantity, 6) : bcsub($running, (string) $movement->quantity, 6);
            if ($request->filled('from') && $movement->business_date?->lt($request->date('from'))) {
                continue;
            }
            $items[] = ['id' => $movement->id, 'business_date' => $movement->business_date?->toDateString(), 'posted_at' => $movement->posted_at, 'movement_type' => $movement->movement_type, 'direction' => $movement->direction, 'quantity' => $movement->quantity, 'running_quantity' => $running, 'document_number' => $movement->source_document_number, 'product' => ['id' => $movement->product_service_id, 'code' => $movement->productService?->code, 'name' => $movement->productService?->name], 'warehouse' => $movement->warehouse?->name, 'stock_location' => $movement->stockLocation?->name, 'unit_code' => $movement->unit_code_snapshot, 'unit_cost' => $this->costVisible($request) ? $movement->unit_cost : null, 'total_cost' => $this->costVisible($request) ? $movement->total_cost : null, 'currency_code' => $this->costVisible($request) ? $movement->currency_code : null];
        }

        return ['items' => $items, 'as_of' => $request->input('as_of') ?: now()->toDateString(), 'quantity_basis' => 'posted movements in business-date, posted-at, id order', 'freshness' => now()->toIso8601String()];
    }

    public function report(string $report, Company $company, Request $request): array
    {
        $metadata = ['report' => $report, 'as_of' => $request->input('as_of') ?: now()->toDateString(), 'quantity_basis' => 'authoritative posted Inventory movements and balances', 'freshness' => now()->toIso8601String()];
        $data = match ($report) {
            'inventory_position' => InventoryBalance::where('company_id', $company->id)->where('status', 'active')->with(['productService', 'warehouse', 'stockLocation'])->get(),
            'stock_card' => $this->stockCard($company, $request)['items'],
            'movement_register' => StockMovement::where('company_id', $company->id)->where('status', 'posted')->with(['productService', 'warehouse', 'stockLocation'])->latest('posted_at')->get(),
            'low_stock', 'out_of_stock' => collect($this->conditions($company))->when($report === 'low_stock', fn ($items) => $items->where('type', 'low_stock'))->when($report === 'out_of_stock', fn ($items) => $items->where('type', 'out_of_stock'))->values(),
            'count_variances' => StockCountItem::where('company_id', $company->id)->where('variance_status', 'variance')->with(['count', 'productService'])->get(),
            'adjustments' => InventoryAdjustment::where('company_id', $company->id)->with('lines')->latest('created_at')->get(),
            'transfers' => StockTransfer::where('company_id', $company->id)->with('lines')->latest('created_at')->get(),
            'reservations' => StockReservation::where('company_id', $company->id)->with('productService')->latest('created_at')->get(),
            'valuation' => $this->costVisible($request) ? InventoryValuationRecord::where('company_id', $company->id)->with('productService')->latest('effective_at')->get() : throw new RegistryConflictException('Inventory valuation is restricted to authorized users.'),
            'expected_receipts' => [],
            default => throw new RegistryConflictException('Unsupported Inventory report.'),
        };

        return ['data' => $data, 'meta' => $metadata + ($report === 'expected_receipts' ? ['source_status' => 'deferred_to_mds_400', 'records' => 0] : [])];
    }

    public function barcode(Company $company, string $barcode): ?ProductService
    {
        return ProductService::where('company_id', $company->id)->where('barcode', $barcode)->where('status', 'active')->where('stock_managed', true)->with('baseUnit')->first();
    }

    private function postAdjustment(InventoryAdjustment $record, Company $company, Request $request): void
    {
        if ($record->status === 'posted') {
            return;
        }
        if ($record->status !== 'approved') {
            throw new RegistryConflictException('Only Approved Inventory Adjustments can be posted.', ['status' => $record->status]);
        }
        foreach ($record->lines as $line) {
            $movement = $this->inventory->postAdjustmentMovement($company, $line->product_service_id, $line->warehouse_id, $line->stock_location_id, $line->unit_of_measure_id, $line->direction, $line->quantity, $record->business_date, InventoryAdjustment::class, $record->id, $line->id, $record->adjustment_number, $record->explanation, $record->reason_code_id, $request, $line->unit_cost, $line->total_cost, $line->currency_code, $line->cost_source);
            $line->movement_id = $movement->id;
            $line->save();
        }
        $record->status = 'posted';
        $record->posted_by = $request->user()?->id;
        $record->posted_at = now();
    }

    private function postCount(StockCount $count, Company $company, Request $request): void
    {
        if ($count->status === 'posted') {
            return;
        }
        if ($count->status !== 'approved') {
            throw new RegistryConflictException('Only Approved Physical Counts can be posted.', ['status' => $count->status]);
        }
        foreach ($count->items as $item) {
            if (bccomp((string) ($item->variance_quantity ?? 0), '0', 6) === 0) {
                continue;
            }
            $adjustment = InventoryAdjustment::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'adjustment_number' => $this->numbers->next($company->id, 'inventory_adjustment'), 'source_type' => 'physical_count', 'source_reference' => $count->count_number, 'business_date' => $count->business_date, 'evidence_reference' => $count->evidence_reference, 'explanation' => 'Physical Count variance for '.$count->count_number, 'status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'stock_count_id' => $count->id, 'created_by' => $request->user()?->id]);
            $direction = bccomp((string) $item->variance_quantity, '0', 6) > 0 ? 'in' : 'out';
            $quantity = ltrim((string) $item->variance_quantity, '-');
            $line = InventoryAdjustmentLine::create(['id' => (string) Str::uuid(), 'inventory_adjustment_id' => $adjustment->id, 'company_id' => $company->id, 'product_service_id' => $item->product_service_id, 'warehouse_id' => $item->warehouse_id, 'stock_location_id' => $item->stock_location_id, 'unit_of_measure_id' => $item->unit_of_measure_id, 'quantity' => $quantity, 'direction' => $direction, 'expected_quantity' => $item->expected_quantity, 'resulting_quantity' => $item->counted_quantity, 'product_code_snapshot' => $item->product_code_snapshot, 'product_name_snapshot' => $item->product_name_snapshot, 'unit_code_snapshot' => $item->unit_code_snapshot, 'unit_name_snapshot' => $item->unit_name_snapshot, 'stock_count_item_id' => $item->id]);
            $movement = $this->inventory->postAdjustmentMovement($company, $line->product_service_id, $line->warehouse_id, $line->stock_location_id, $line->unit_of_measure_id, $direction, $quantity, $count->business_date, InventoryAdjustment::class, $adjustment->id, $line->id, $adjustment->adjustment_number, $adjustment->explanation, null, $request);
            $line->movement_id = $movement->id;
            $line->save();
            $adjustment->status = 'posted';
            $adjustment->posted_by = $request->user()?->id;
            $adjustment->posted_at = now();
            $adjustment->save();
            $item->adjustment_id = $adjustment->id;
            $item->variance_status = 'posted';
            $item->recount_required = false;
            $item->save();
        }
        $count->status = 'posted';
        $count->posted_by = $request->user()?->id;
        $count->posted_at = now();
    }

    private function writeAdjustmentLines(InventoryAdjustment $record, array $lines, Company $company): void
    {
        foreach ($lines as $input) {
            $product = $this->validateProductLine($company, $input);
            $expected = (string) (InventoryBalance::where('company_id', $company->id)->where('product_service_id', $product->id)->where('warehouse_id', $input['warehouse_id'])->where('stock_location_id', $input['stock_location_id'])->where('status', 'active')->value('on_hand') ?? '0');
            $unitCost = isset($input['unit_cost']) ? (string) $input['unit_cost'] : null;
            if ($unitCost !== null && (! isset($input['currency_code']) || ! isset($input['cost_source']))) {
                throw new RegistryConflictException('A source currency and cost source are required when valuation cost is supplied.');
            }
            $resulting = $input['direction'] === 'in' ? bcadd($expected, (string) $input['quantity'], 6) : bcsub($expected, (string) $input['quantity'], 6);
            InventoryAdjustmentLine::create(['id' => (string) Str::uuid(), 'inventory_adjustment_id' => $record->id, 'company_id' => $company->id, 'product_service_id' => $product->id, 'warehouse_id' => $input['warehouse_id'], 'stock_location_id' => $input['stock_location_id'], 'unit_of_measure_id' => $product->base_unit_id, 'quantity' => $input['quantity'], 'direction' => $input['direction'], 'expected_quantity' => $expected, 'resulting_quantity' => $resulting, 'product_code_snapshot' => $product->code, 'product_name_snapshot' => $product->name, 'unit_code_snapshot' => $product->baseUnit?->code ?? 'BASE', 'unit_name_snapshot' => $product->baseUnit?->name ?? 'Base unit', 'unit_cost' => $unitCost, 'total_cost' => $unitCost === null ? null : bcmul($unitCost, (string) $input['quantity'], 6), 'currency_code' => $input['currency_code'] ?? null, 'cost_source' => $input['cost_source'] ?? null]);
        }
    }

    private function reason(Company $company, string $id, string $explanation, ?string $evidence): ReasonCode
    {
        $reason = ReasonCode::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->whereIn('domain', ['inventory', 'INVENTORY_ADJUSTMENT', 'inventory_adjustment'])->first();
        if (! $reason) {
            throw new RegistryConflictException('An active same-company Inventory Adjustment Reason Code is required.', ['dependency' => 'reason_code']);
        }
        if ($reason->requires_explanation && trim($explanation) === '') {
            throw new RegistryConflictException('The selected Reason Code requires an explanation.');
        }
        if ($reason->requires_evidence && ! $evidence) {
            throw new RegistryConflictException('The selected Reason Code requires evidence.');
        }

        return $reason;
    }

    private function validateProductLine(Company $company, array $input): ProductService
    {
        $product = ProductService::where('company_id', $company->id)->whereKey($input['product_service_id'])->where('status', 'active')->where('stock_managed', true)->with('baseUnit')->first();
        if (! $product) {
            throw new RegistryConflictException('Only active same-company stock-managed Products may be used by Inventory.', ['dependency' => 'product_service']);
        }
        $this->validateLocation($company, $input['warehouse_id'], $input['stock_location_id']);
        if (isset($input['unit_of_measure_id']) && $input['unit_of_measure_id'] !== $product->base_unit_id) {
            throw new RegistryConflictException('Inventory quantities must use the Product base Unit of Measure.', ['dependency' => 'unit_of_measure']);
        }
        if ($product->baseUnit && ! $product->baseUnit->allows_fractional && bccomp((string) $input['quantity'], (string) (int) $input['quantity'], 6) !== 0) {
            throw new RegistryConflictException('The selected Unit of Measure does not allow fractional quantities.');
        }

        return $product;
    }

    private function validateLocation(Company $company, ?string $warehouseId, ?string $locationId): void
    {
        $warehouse = Warehouse::where('company_id', $company->id)->whereKey($warehouseId)->where('status', 'active')->first();
        $location = StockLocation::where('company_id', $company->id)->whereKey($locationId)->where('status', 'active')->first();
        if (! $warehouse || ! $location || (string) $location->warehouse_id !== (string) $warehouse->id) {
            throw new RegistryConflictException('The Warehouse and Stock Location must be active and belong to the same company and Warehouse.');
        }
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

    private function reservationEvent(StockReservation $reservation, string $event, string $quantity, Request $request, ?string $reason): void
    {
        StockReservationEvent::create(['id' => (string) Str::uuid(), 'stock_reservation_id' => $reservation->id, 'company_id' => $reservation->company_id, 'event_type' => $event, 'quantity' => $quantity, 'actor_id' => $request->user()?->id, 'reason' => $reason, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function availableForRule(Company $company, InventoryReorderRule $rule): string
    {
        return InventoryBalance::where('company_id', $company->id)->where('product_service_id', $rule->product_service_id)->where('status', 'active')->when($rule->warehouse_id, fn ($q) => $q->where('warehouse_id', $rule->warehouse_id))->when($rule->stock_location_id, fn ($q) => $q->where('stock_location_id', $rule->stock_location_id))->get()->reduce(fn (string $total, InventoryBalance $balance) => bcadd($total, $balance->available, 6), '0');
    }

    private function transition(InventoryAdjustment $record, string $from, string $to): void
    {
        if ($record->status !== $from) {
            throw new RegistryConflictException('Inventory Adjustment cannot transition from its current status.', ['status' => $record->status]);
        } $record->status = $to;
    }

    private function assertVersion($record, array $input): void
    {
        if (array_key_exists('version', $input) && (int) $input['version'] !== (int) $record->version) {
            throw new RegistryConflictException('This Inventory record was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
    }

    private function costVisible(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission('inventory.cost.view', $request->attributes->get('company')?->id);
    }

    private function pagination($page): array
    {
        return ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    private function audit(Request $request, string $action, $record, array $before, array $after, string $description): void
    {
        $this->audit->record($request, $action, $record, $record->company_id, $before, $after, null, 'Inventory', $description);
    }
}
