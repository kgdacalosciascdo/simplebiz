<?php

namespace App\Services;

use App\Events\SalesLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\BusinessTransaction;
use App\Models\Company;
use App\Models\ReceivableOpenItem;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalesAdjustment;
use App\Models\SalesAdjustmentLine;
use App\Models\SalesAdjustmentStatusHistory;
use App\Models\SalesReceivableEffect;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\SalesReturnStatusHistory;
use App\Models\StockMovement;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SalesCompletionService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers, private readonly InventoryService $inventory) {}

    public function eligibleReturnLines(Company $company, Request $request): array
    {
        $query = SaleLine::where('company_id', $company->id)
            ->whereHas('sale', fn ($sale) => $sale->where('company_id', $company->id)->where('status', 'posted'))
            ->with(['sale.customer', 'sale.currency']);

        if ($request->filled('sale_id')) {
            $query->whereHas('sale', fn ($sale) => $sale->whereKey($request->string('sale_id')));
        }

        $items = $query->orderBy('created_at')->get()->map(function (SaleLine $line) use ($company) {
            $returned = $this->returnedQuantity($line->id, $company, null);

            return [
                'id' => $line->id,
                'sale_id' => $line->sale_id,
                'sale_number' => $line->sale?->sale_number,
                'sale_date' => $line->sale?->sale_date?->toDateString(),
                'customer' => $line->sale?->customer?->display_name,
                'currency' => $line->sale?->currency?->code,
                'product_service_id' => $line->product_service_id,
                'product_code' => $line->item_code_snapshot,
                'product_name' => $line->description_snapshot,
                'warehouse_id' => $line->warehouse_id,
                'stock_location_id' => $line->stock_location_id,
                'original_quantity' => (string) $line->quantity,
                'previously_returned_quantity' => $returned,
                'remaining_returnable_quantity' => bcsub((string) $line->quantity, $returned, 6),
                'unit_price' => (string) $line->unit_price,
                'stock_managed' => (bool) $line->stock_managed_snapshot,
                'service' => (bool) $line->service_snapshot,
            ];
        })->filter(fn (array $line) => bccomp($line['remaining_returnable_quantity'], '0', 6) > 0)->values()->all();

        return ['items' => $items];
    }

    public function returns(Company $company, Request $request): array
    {
        $query = SalesReturn::where('company_id', $company->id)->with(['sale', 'customer', 'currency', 'lines'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('sale_id'), fn ($q) => $q->where('sale_id', $request->string('sale_id')))->when($request->filled('q'), fn ($q) => $q->where('return_number', 'ilike', '%'.$request->string('q').'%'));
        $page = $query->latest('return_date')->latest('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function adjustments(Company $company, Request $request): array
    {
        $query = SalesAdjustment::where('company_id', $company->id)->with(['sale', 'customer', 'currency', 'lines'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('adjustment_type'), fn ($q) => $q->where('adjustment_type', $request->string('adjustment_type')))->when($request->filled('sale_id'), fn ($q) => $q->where('sale_id', $request->string('sale_id')));
        $page = $query->latest('adjustment_date')->latest('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function createReturn(array $input, Company $company, Request $request): SalesReturn
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $sale = Sale::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($input['sale_id']);
            if ($sale->status !== 'posted') {
                throw new RegistryConflictException('Sales Returns require a Posted Sale.', ['status' => $sale->status]);
            }

            $return = SalesReturn::create([
                'id' => (string) Str::uuid(), 'company_id' => $company->id, 'return_number' => $this->numbers->next($company->id, 'sales_return'), 'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id, 'currency_id' => $sale->currency_id, 'branch_id' => $sale->branch_id, 'return_date' => $input['return_date'],
                'reason_code_id' => $input['reason_code_id'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'explanation' => $input['explanation'],
                'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key'),
            ]);

            $subtotal = '0';
            $tax = '0';
            $total = '0';
            foreach ($input['lines'] as $lineInput) {
                $saleLine = $sale->lines->firstWhere('id', $lineInput['sale_line_id']);
                if (! $saleLine) {
                    throw new RegistryConflictException('Every Sales Return line must belong to the selected Sale.', ['dependency' => 'sale_line']);
                }
                $previouslyReturned = $this->returnedQuantity($saleLine->id, $company, null);
                $remaining = bcsub((string) $saleLine->quantity, $previouslyReturned, 6);
                $quantity = (string) $lineInput['quantity'];
                if (bccomp($quantity, $remaining, 6) > 0) {
                    throw new RegistryConflictException('The Sales Return quantity exceeds the remaining returnable quantity.', ['dependency' => 'returnable_quantity', 'remaining' => $remaining]);
                }
                if ($saleLine->stock_managed_snapshot && (! $saleLine->warehouse_id || ! $saleLine->stock_location_id)) {
                    throw new RegistryConflictException('Stock-managed Sales Returns require the original Warehouse and Stock Location.', ['dependency' => 'inventory_location']);
                }
                $ratio = bcdiv($quantity, (string) $saleLine->quantity, 12);
                $lineTaxable = bcmul((string) $saleLine->taxable_amount, $ratio, 6);
                $lineTax = bcmul((string) $saleLine->tax_amount, $ratio, 6);
                $lineTotal = bcmul((string) $saleLine->net_amount, $ratio, 6);
                SalesReturnLine::create([
                    'id' => (string) Str::uuid(), 'sales_return_id' => $return->id, 'company_id' => $company->id, 'sale_line_id' => $saleLine->id, 'product_service_id' => $saleLine->product_service_id,
                    'unit_of_measure_id' => $saleLine->productService?->base_unit_id, 'warehouse_id' => $saleLine->warehouse_id, 'stock_location_id' => $saleLine->stock_location_id,
                    'original_quantity' => $saleLine->quantity, 'previously_returned_quantity' => $previouslyReturned, 'quantity' => $quantity, 'unit_price' => $saleLine->unit_price,
                    'taxable_amount' => $lineTaxable, 'tax_amount' => $lineTax, 'total_amount' => $lineTotal, 'stock_managed_snapshot' => $saleLine->stock_managed_snapshot,
                    'service_snapshot' => $saleLine->service_snapshot, 'product_code_snapshot' => $saleLine->item_code_snapshot, 'product_name_snapshot' => $saleLine->description_snapshot,
                    'unit_code_snapshot' => $saleLine->unit_code, 'unit_name_snapshot' => $saleLine->unit_name, 'reason' => $lineInput['reason'] ?? $input['explanation'],
                ]);
                $subtotal = bcadd($subtotal, bcsub($lineTotal, $lineTax, 6), 6);
                $tax = bcadd($tax, $lineTax, 6);
                $total = bcadd($total, $lineTotal, 6);
            }
            $return->update(['subtotal' => $subtotal, 'tax_amount' => $tax, 'total_amount' => $total]);
            $this->history($return, null, 'draft', $company, $request);
            $this->audit->record($request, 'sales.return.created', $return, $company->id, [], $return->toArray(), null, 'Sales Return created', 'A linked Sales Return draft was created from a posted Sale.');

            return $return->fresh(['sale', 'customer', 'currency', 'lines']);
        });
    }

    public function transitionReturn(SalesReturn $return, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): SalesReturn
    {
        return DB::transaction(function () use ($return, $action, $company, $request, $reason, $version) {
            $locked = SalesReturn::where('company_id', $company->id)->with(['lines', 'sale'])->lockForUpdate()->findOrFail($return->id);
            $this->assertVersion($locked, $version);
            $from = $locked->status;
            $actor = $request->user()?->id;
            if ($action === 'submit') {
                $this->requireStatus($from, 'draft', 'Only Draft Sales Returns can be submitted.');
                $locked->status = 'for_approval';
                $locked->submitted_by = $actor;
                $locked->submitted_at = now();
            } elseif ($action === 'review') {
                $this->requireStatus($from, 'for_approval', 'Only Sales Returns waiting for approval can be reviewed.');
                $locked->reviewed_by = $actor;
                $locked->reviewed_at = now();
            } elseif ($action === 'approve') {
                $this->requireStatus($from, 'for_approval', 'Only Sales Returns waiting for approval can be approved.');
                if ($actor && (int) $actor === (int) $locked->created_by) {
                    throw new RegistryConflictException('The preparer cannot approve the same Sales Return.', ['segregation' => true]);
                }
                $locked->status = 'approved';
                $locked->approved_by = $actor;
                $locked->approved_at = now();
            } elseif ($action === 'post') {
                $this->postReturn($locked, $company, $request);
            } elseif ($action === 'reverse') {
                $this->reverseReturn($locked, $company, $request, $reason);
            } elseif ($action === 'cancel') {
                if (! in_array($from, ['draft', 'for_approval', 'approved'], true) || ! trim((string) $reason)) {
                    throw new RegistryConflictException('A cancellable Sales Return and reason are required.');
                }
                $locked->status = 'cancelled';
            } else {
                throw new RegistryConflictException('Unsupported Sales Return action.');
            }
            if ($locked->status !== $from || in_array($action, ['review', 'post', 'reverse'], true)) {
                $locked->version++;
                $locked->save();
                $this->history($locked, $from, $locked->status, $company, $request, $reason);
            }
            $this->audit->record($request, 'sales.return.'.$action, $locked, $company->id, ['status' => $from], ['status' => $locked->status], $reason, 'Sales Return '.$action, 'Sales Return lifecycle action completed with linked source effects.');
            SalesLifecycleEvent::dispatch('sales.return.'.($action === 'post' ? 'posted' : $action), $company->id, SalesReturn::class, $locked->id);

            return $locked->fresh(['sale', 'customer', 'currency', 'lines', 'statusHistory']);
        });
    }

    public function createAdjustment(array $input, Company $company, Request $request): SalesAdjustment
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $sale = Sale::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($input['sale_id']);
            if ($sale->status !== 'posted') {
                throw new RegistryConflictException('Sales Adjustments require a Posted Sale.', ['status' => $sale->status]);
            }
            $adjustment = SalesAdjustment::create([
                'id' => (string) Str::uuid(), 'company_id' => $company->id, 'adjustment_number' => $this->numbers->next($company->id, 'sales_adjustment'), 'adjustment_type' => $input['adjustment_type'],
                'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'currency_id' => $sale->currency_id, 'branch_id' => $sale->branch_id, 'adjustment_date' => $input['adjustment_date'],
                'reason_code_id' => $input['reason_code_id'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'explanation' => $input['explanation'], 'status' => 'draft',
                'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key'),
            ]);
            $amount = '0';
            $tax = '0';
            foreach ($input['lines'] as $line) {
                $sourceLine = null;
                if (! empty($line['sale_line_id'])) {
                    $sourceLine = $sale->lines->firstWhere('id', $line['sale_line_id']);
                    if (! $sourceLine) {
                        throw new RegistryConflictException('Adjustment line is outside the selected Sale.', ['dependency' => 'sale_line']);
                    }
                }
                $lineAmount = ! empty($line['quantity']) ? bcmul((string) $line['quantity'], (string) $line['unit_amount'], 6) : (string) $line['unit_amount'];
                $lineTax = (string) ($line['tax_amount'] ?? '0');
                SalesAdjustmentLine::create(['id' => (string) Str::uuid(), 'sales_adjustment_id' => $adjustment->id, 'company_id' => $company->id, 'sale_line_id' => $sourceLine?->id, 'product_service_id' => $sourceLine?->product_service_id, 'description' => $line['description'], 'quantity' => $line['quantity'] ?? null, 'unit_amount' => $line['unit_amount'], 'amount' => $lineAmount, 'tax_amount' => $lineTax, 'total_amount' => bcadd($lineAmount, $lineTax, 6)]);
                $amount = bcadd($amount, $lineAmount, 6);
                $tax = bcadd($tax, $lineTax, 6);
            }
            if (bccomp(bcadd($amount, $tax, 6), '0', 6) <= 0) {
                throw new RegistryConflictException('A Sales Adjustment must have a positive amount.');
            }
            $adjustment->update(['amount' => $amount, 'tax_amount' => $tax, 'total_amount' => bcadd($amount, $tax, 6)]);
            $this->adjustmentHistory($adjustment, null, 'draft', $company, $request);
            $this->audit->record($request, 'sales.adjustment.created', $adjustment, $company->id, [], $adjustment->toArray(), null, 'Sales Adjustment created', 'A separately numbered linked Sales Adjustment draft was created.');

            return $adjustment->fresh(['sale', 'customer', 'currency', 'lines']);
        });
    }

    public function transitionAdjustment(SalesAdjustment $adjustment, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): SalesAdjustment
    {
        return DB::transaction(function () use ($adjustment, $action, $company, $request, $reason, $version) {
            $locked = SalesAdjustment::where('company_id', $company->id)->with(['lines', 'sale'])->lockForUpdate()->findOrFail($adjustment->id);
            $this->assertVersion($locked, $version);
            $from = $locked->status;
            $actor = $request->user()?->id;
            if ($action === 'submit') {
                $this->requireStatus($from, 'draft', 'Only Draft Sales Adjustments can be submitted.');
                $locked->status = 'for_approval';
                $locked->submitted_by = $actor;
                $locked->submitted_at = now();
            } elseif ($action === 'approve') {
                $this->requireStatus($from, 'for_approval', 'Only Sales Adjustments waiting for approval can be approved.');
                if ($actor && (int) $actor === (int) $locked->created_by) {
                    throw new RegistryConflictException('The preparer cannot approve the same Sales Adjustment.', ['segregation' => true]);
                }
                $locked->status = 'approved';
                $locked->approved_by = $actor;
                $locked->approved_at = now();
            } elseif ($action === 'post') {
                $this->postAdjustment($locked, $company, $request);
            } elseif ($action === 'reverse') {
                $this->reverseAdjustment($locked, $company, $request, $reason);
            } elseif ($action === 'cancel') {
                if (! in_array($from, ['draft', 'for_approval'], true) || ! trim((string) $reason)) {
                    throw new RegistryConflictException('A cancellable Sales Adjustment and reason are required.');
                }
                $locked->status = 'cancelled';
            } else {
                throw new RegistryConflictException('Unsupported Sales Adjustment action.');
            }
            if ($locked->status !== $from || in_array($action, ['post', 'reverse'], true)) {
                $locked->version++;
                $locked->save();
                $this->adjustmentHistory($locked, $from, $locked->status, $company, $request, $reason);
            }
            $this->audit->record($request, 'sales.adjustment.'.$action, $locked, $company->id, ['status' => $from], ['status' => $locked->status], $reason, 'Sales Adjustment '.$action, 'Sales Adjustment lifecycle action completed with linked receivable and accounting effects.');
            SalesLifecycleEvent::dispatch('sales.adjustment.'.($action === 'post' ? 'posted' : $action), $company->id, SalesAdjustment::class, $locked->id);

            return $locked->fresh(['sale', 'customer', 'currency', 'lines', 'statusHistory']);
        });
    }

    public function reverseSale(Sale $sale, Company $company, Request $request, ?string $reason, ?int $version = null): Sale
    {
        return DB::transaction(function () use ($sale, $company, $request, $reason, $version) {
            $locked = Sale::where('company_id', $company->id)->with(['inventoryMovements', 'receivable'])->lockForUpdate()->findOrFail($sale->id);
            $this->assertVersion($locked, $version);
            if ($locked->status !== 'posted' || ! trim((string) $reason)) {
                throw new RegistryConflictException('Only Posted Sales can be reversed and a reason is required.', ['status' => $locked->status]);
            }
            if (($locked->receivable?->applied_amount ?? 0) > 0 || $locked->salesReturns()->where('status', 'posted')->exists() || $locked->salesAdjustments()->where('status', 'posted')->exists()) {
                throw new RegistryConflictException('This Sale has downstream settlement or correction effects and cannot be reversed until those effects are governed.', ['dependency' => 'downstream_effects']);
            }
            $business = $this->business($company, 'sale_reversal', $locked->sale_date, $request, 'sale-reversal:'.$locked->id);
            $accounting = $this->reverseAccounting($locked->accounting_transaction_id, $company, $request, $business->id, 'Reversal of Sale '.$locked->sale_number, $locked->sale_date->toDateString());
            foreach ($locked->inventoryMovements as $movement) {
                $this->inventory->reverseStockMovement($movement, $company, $request);
            }
            if ($locked->receivable) {
                $this->recordEffect($locked->receivable, Sale::class, $locked->id, 'sale_reversal', bcmul((string) $locked->receivable->remaining_amount, '-1', 6), $locked->currency_id, 'Sale reversal '.$locked->sale_number, $business->id, $accounting, $company, $request);
            }
            $locked->status = 'reversed';
            $locked->reversed_by = $request->user()?->id;
            $locked->reversed_at = now();
            $locked->reversal_reason = $reason;
            $locked->version++;
            $locked->accounting_transaction_id = $accounting;
            $locked->business_transaction_id = $business->id;
            $locked->save();
            SalesLifecycleEvent::dispatch('EVT-SAL-006', $company->id, Sale::class, $locked->id);
            $this->audit->record($request, 'sales.reversed', $locked, $company->id, ['status' => 'posted'], $locked->toArray(), $reason, 'Sale reversed', 'A posted Sale was reversed through linked accounting, receivable, and inventory counter-effects.');

            return $locked->fresh(['lines', 'customer', 'currency', 'receivable', 'inventoryMovements']);
        });
    }

    private function postReturn(SalesReturn $return, Company $company, Request $request): void
    {
        if ($return->status !== 'approved') {
            throw new RegistryConflictException('Only Approved Sales Returns can be posted.', ['status' => $return->status]);
        }
        $sale = Sale::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($return->sale_id);
        if ($sale->status !== 'posted') {
            throw new RegistryConflictException('The source Sale must remain Posted when a Sales Return is posted.');
        }
        foreach ($return->lines as $line) {
            $eligible = bcsub((string) $line->original_quantity, $this->returnedQuantity($line->sale_line_id, $company, $return->id), 6);
            if (bccomp((string) $line->quantity, $eligible, 6) > 0) {
                throw new RegistryConflictException('Concurrent Sales Returns cannot exceed the remaining returnable quantity.', ['dependency' => 'returnable_quantity', 'remaining' => $eligible]);
            }
        }
        $movements = $this->inventory->postSalesReturn($return->lines->all(), $company, $request, SalesReturn::class, $return->id, $return->return_number, $return->return_date);
        foreach ($movements as $lineId => $movement) {
            $return->lines->firstWhere('id', $lineId)?->update(['inventory_movement_id' => $movement->id]);
        }
        $receivable = ReceivableOpenItem::where('company_id', $company->id)->where('source_sale_id', $sale->id)->lockForUpdate()->first();
        $total = (string) $return->total_amount;
        $applied = $receivable ? min((float) $total, (float) $receivable->remaining_amount) : 0.0;
        $credit = bcsub($total, number_format($applied, 6, '.', ''), 6);
        $business = $this->business($company, 'sales_return', $return->return_date, $request, 'sales-return:'.$return->id);
        $accounts = $this->postingAccounts($company, bccomp((string) $return->tax_amount, '0', 6) > 0, bccomp($credit, '0', 6) > 0);
        $accounting = $this->createAccounting($business, $company, $request, 'sales_return', $return->return_date, $accounts, $return->currency()->value('code'), bcsub($total, (string) $return->tax_amount, 6), (string) $return->tax_amount, number_format($applied, 6, '.', ''), $credit, true, 'Sales Return '.$return->return_number);
        if ($receivable && bccomp(number_format($applied, 6, '.', ''), '0', 6) > 0) {
            $this->recordEffect($receivable, SalesReturn::class, $return->id, 'sales_return_posted', bcmul(number_format($applied, 6, '.', ''), '-1', 6), $return->currency_id, 'Sales Return '.$return->return_number, $business->id, $accounting, $company, $request);
        }
        $return->update(['customer_credit_amount' => $credit, 'refund_status' => bccomp($credit, '0', 6) > 0 ? 'pending_mds500' : 'not_required', 'status' => 'posted', 'business_transaction_id' => $business->id, 'accounting_transaction_id' => $accounting, 'posted_by' => $request->user()?->id, 'posted_at' => now()]);
        $this->refreshSaleReturnStatus($sale, $company);
    }

    private function postAdjustment(SalesAdjustment $adjustment, Company $company, Request $request): void
    {
        if ($adjustment->status !== 'approved') {
            throw new RegistryConflictException('Only Approved Sales Adjustments can be posted.', ['status' => $adjustment->status]);
        }
        $sale = Sale::where('company_id', $company->id)->lockForUpdate()->findOrFail($adjustment->sale_id);
        if ($sale->status !== 'posted') {
            throw new RegistryConflictException('The source Sale must remain Posted when a Sales Adjustment is posted.');
        }
        $receivable = ReceivableOpenItem::where('company_id', $company->id)->where('source_sale_id', $sale->id)->lockForUpdate()->first();
        if (! $receivable) {
            throw new RegistryConflictException('The source Sale has no receivable open item for this adjustment.');
        }
        $total = (string) $adjustment->total_amount;
        $isDebit = $adjustment->adjustment_type === 'debit';
        $applied = $isDebit ? $total : number_format(min((float) $total, (float) $receivable->remaining_amount), 6, '.', '');
        $credit = $isDebit ? '0' : bcsub($total, $applied, 6);
        $business = $this->business($company, 'sales_'.$adjustment->adjustment_type.'_adjustment', $adjustment->adjustment_date, $request, 'sales-adjustment:'.$adjustment->id);
        $accounts = $this->postingAccounts($company, bccomp((string) $adjustment->tax_amount, '0', 6) > 0, bccomp($credit, '0', 6) > 0);
        $accounting = $this->createAccounting($business, $company, $request, 'sales_'.$adjustment->adjustment_type.'_adjustment', $adjustment->adjustment_date, $accounts, $adjustment->currency()->value('code'), bcsub($total, (string) $adjustment->tax_amount, 6), (string) $adjustment->tax_amount, $applied, $credit, ! $isDebit, 'Sales '.ucfirst($adjustment->adjustment_type).' Adjustment '.$adjustment->adjustment_number);
        $delta = $isDebit ? $total : bcmul($applied, '-1', 6);
        $this->recordEffect($receivable, SalesAdjustment::class, $adjustment->id, 'sales_'.$adjustment->adjustment_type.'_adjustment_posted', $delta, $adjustment->currency_id, 'Sales Adjustment '.$adjustment->adjustment_number, $business->id, $accounting, $company, $request);
        $adjustment->update(['customer_credit_amount' => $credit, 'refund_status' => bccomp($credit, '0', 6) > 0 ? 'pending_mds500' : 'not_required', 'status' => 'posted', 'business_transaction_id' => $business->id, 'accounting_transaction_id' => $accounting, 'posted_by' => $request->user()?->id, 'posted_at' => now()]);
    }

    private function reverseReturn(SalesReturn $return, Company $company, Request $request, ?string $reason): void
    {
        if ($return->status !== 'posted' || ! trim((string) $reason)) {
            throw new RegistryConflictException('Only Posted Sales Returns can be reversed and a reason is required.');
        }
        foreach ($return->lines->pluck('inventory_movement_id')->filter()->unique() as $movementId) {
            $movement = StockMovement::where('company_id', $company->id)->whereKey($movementId)->lockForUpdate()->firstOrFail();
            $this->inventory->reverseStockMovement($movement, $company, $request);
        }
        $business = $this->business($company, 'sales_return_reversal', now()->toDateString(), $request, 'sales-return-reversal:'.$return->id);
        $accounting = $this->reverseAccounting($return->accounting_transaction_id, $company, $request, $business->id, 'Reversal of Sales Return '.$return->return_number, now()->toDateString());
        $effect = SalesReceivableEffect::where('company_id', $company->id)->where('source_type', SalesReturn::class)->where('source_id', $return->id)->where('effect_type', 'sales_return_posted')->lockForUpdate()->first();
        if ($effect) {
            $this->recordEffect($effect->receivable()->lockForUpdate()->firstOrFail(), SalesReturn::class, $return->id, 'sales_return_reversed', bcmul((string) $effect->amount_delta, '-1', 6), $effect->currency_id, 'Reversal of Sales Return '.$return->return_number, $business->id, $accounting, $company, $request, $effect->id);
        }
        $return->update(['status' => 'reversed', 'refund_status' => 'reversed', 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
    }

    private function reverseAdjustment(SalesAdjustment $adjustment, Company $company, Request $request, ?string $reason): void
    {
        if ($adjustment->status !== 'posted' || ! trim((string) $reason)) {
            throw new RegistryConflictException('Only Posted Sales Adjustments can be reversed and a reason is required.');
        }
        $business = $this->business($company, 'sales_adjustment_reversal', now()->toDateString(), $request, 'sales-adjustment-reversal:'.$adjustment->id);
        $accounting = $this->reverseAccounting($adjustment->accounting_transaction_id, $company, $request, $business->id, 'Reversal of Sales Adjustment '.$adjustment->adjustment_number, now()->toDateString());
        $effect = SalesReceivableEffect::where('company_id', $company->id)->where('source_type', SalesAdjustment::class)->where('source_id', $adjustment->id)->where('effect_type', 'sales_'.$adjustment->adjustment_type.'_adjustment_posted')->lockForUpdate()->firstOrFail();
        $this->recordEffect($effect->receivable()->lockForUpdate()->firstOrFail(), SalesAdjustment::class, $adjustment->id, 'sales_'.$adjustment->adjustment_type.'_adjustment_reversed', bcmul((string) $effect->amount_delta, '-1', 6), $effect->currency_id, 'Reversal of Sales Adjustment '.$adjustment->adjustment_number, $business->id, $accounting, $company, $request, $effect->id);
        $adjustment->update(['status' => 'reversed', 'refund_status' => 'reversed', 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
    }

    private function recordEffect(ReceivableOpenItem $receivable, string $sourceType, string $sourceId, string $effectType, string $delta, string $currencyId, string $description, ?string $businessId, ?string $accountingId, Company $company, Request $request, ?string $reversalEffectId = null): SalesReceivableEffect
    {
        $existing = SalesReceivableEffect::where('company_id', $company->id)->where('source_type', $sourceType)->where('source_id', $sourceId)->where('effect_type', $effectType)->first();
        if ($existing) {
            return $existing;
        }
        if ((string) $receivable->currency_id !== (string) $currencyId) {
            throw new RegistryConflictException('The receivable effect currency must match the source Sale.');
        }
        $effect = SalesReceivableEffect::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receivable_open_item_id' => $receivable->id, 'source_type' => $sourceType, 'source_id' => $sourceId, 'effect_type' => $effectType, 'amount_delta' => $delta, 'currency_id' => $currencyId, 'description' => $description, 'created_by' => $request->user()?->id, 'business_transaction_id' => $businessId, 'accounting_transaction_id' => $accountingId, 'reversal_effect_id' => $reversalEffectId]);
        $this->refreshReceivable($receivable->fresh(), $company);

        return $effect;
    }

    private function refreshReceivable(ReceivableOpenItem $receivable, Company $company): void
    {
        $effects = SalesReceivableEffect::where('company_id', $company->id)->where('receivable_open_item_id', $receivable->id)->get();
        $delta = $effects->reduce(fn (string $sum, SalesReceivableEffect $effect) => bcadd($sum, (string) $effect->amount_delta, 6), '0');
        $newRemaining = bcsub(bcadd((string) $receivable->original_amount, $delta, 6), bcadd((string) $receivable->applied_amount, (string) $receivable->write_off_amount, 6), 6);
        if (bccomp($newRemaining, '0', 6) < 0) {
            throw new RegistryConflictException('The Sales correction exceeds the governed receivable balance.', ['dependency' => 'receivable_balance']);
        }
        $returnNet = $effects->filter(fn ($effect) => str_starts_with($effect->effect_type, 'sales_return_'))->reduce(fn (string $sum, $effect) => bcadd($sum, (string) $effect->amount_delta, 6), '0');
        $creditNet = $effects->filter(fn ($effect) => $effect->effect_type === 'sales_credit_adjustment_posted' || $effect->effect_type === 'sales_credit_adjustment_reversed')->reduce(fn (string $sum, $effect) => bcadd($sum, (string) $effect->amount_delta, 6), '0');
        $debitNet = $effects->filter(fn ($effect) => $effect->effect_type === 'sales_debit_adjustment_posted' || $effect->effect_type === 'sales_debit_adjustment_reversed')->reduce(fn (string $sum, $effect) => bcadd($sum, (string) $effect->amount_delta, 6), '0');
        $receivable->remaining_amount = $newRemaining;
        $receivable->return_amount = bccomp($returnNet, '0', 6) < 0 ? bcmul($returnNet, '-1', 6) : '0';
        $receivable->credit_adjustment_amount = bccomp($creditNet, '0', 6) < 0 ? bcmul($creditNet, '-1', 6) : '0';
        $receivable->debit_adjustment_amount = bccomp($debitNet, '0', 6) > 0 ? $debitNet : '0';
        $receivable->settlement_status = bccomp($newRemaining, '0', 6) === 0 ? ((float) $receivable->applied_amount > 0 ? 'paid' : 'unpaid') : ((float) $receivable->applied_amount > 0 ? 'partially_paid' : 'unpaid');
        $receivable->last_calculated_at = now();
        $receivable->version++;
        $receivable->save();
        $sale = $receivable->sourceSale()->lockForUpdate()->first();
        if ($sale) {
            $sale->paid_amount = $receivable->applied_amount;
            $sale->remaining_amount = $receivable->remaining_amount;
            $sale->settlement_status = $receivable->settlement_status;
            $sale->version++;
            $sale->save();
        }
    }

    private function postingAccounts(Company $company, bool $needsTax, bool $needsCreditLiability): array
    {
        $active = fn (string $classification) => AccountTitle::where('company_id', $company->id)->where('classification', $classification)->where('status', 'active')->where('posting_eligible', true);
        $receivable = (clone $active('asset'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%receivable%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%receivable%'])->orWhereRaw('LOWER(code) LIKE ?', ['%receivable%']))->first();
        $revenue = (clone $active('income'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%revenue%'])->orWhereRaw('LOWER(name) LIKE ?', ['%sales%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%revenue%']))->first();
        $tax = $needsTax ? (clone $active('liability'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%tax%'])->orWhereRaw('LOWER(name) LIKE ?', ['%vat%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%tax%']))->first() : null;
        $credit = $needsCreditLiability ? (clone $active('liability'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%advance%'])->orWhereRaw('LOWER(name) LIKE ?', ['%deposit%'])->orWhereRaw('LOWER(name) LIKE ?', ['%customer%'])->orWhereRaw('LOWER(name) LIKE ?', ['%credit%']))->first() : null;
        if (! $receivable || ! $revenue || ($needsTax && ! $tax) || ($needsCreditLiability && ! $credit)) {
            throw new RegistryConflictException('Sales correction posting is blocked until matching active posting Account Titles are configured.', ['dependency' => 'account_titles', 'receivable' => ! $receivable, 'revenue' => ! $revenue, 'tax' => $needsTax && ! $tax, 'customer_credit' => $needsCreditLiability && ! $credit]);
        }

        return compact('receivable', 'revenue', 'tax', 'credit');
    }

    private function createAccounting(BusinessTransaction $business, Company $company, Request $request, string $type, $date, array $accounts, string $currency, string $net, string $tax, string $receivableAmount, string $creditAmount, bool $creditDirection, string $description): string
    {
        $id = (string) Str::uuid();
        DB::table('accounting_transactions')->insert(['id' => $id, 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => $type, 'status' => 'posted', 'business_date' => $date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
        if ($creditDirection) {
            $this->line($id, $accounts['revenue']->id, $net, '0', $currency, $description.' revenue reduction');
            if (bccomp($tax, '0', 6) > 0) {
                $this->line($id, $accounts['tax']->id, $tax, '0', $currency, $description.' tax reduction');
            }
            if (bccomp($receivableAmount, '0', 6) > 0) {
                $this->line($id, $accounts['receivable']->id, '0', $receivableAmount, $currency, $description.' receivable effect');
            }
            if (bccomp($creditAmount, '0', 6) > 0) {
                $this->line($id, $accounts['credit']->id, '0', $creditAmount, $currency, $description.' customer credit pending MDS-500');
            }
        } else {
            if (bccomp($receivableAmount, '0', 6) > 0) {
                $this->line($id, $accounts['receivable']->id, $receivableAmount, '0', $currency, $description.' receivable effect');
            }
            $this->line($id, $accounts['revenue']->id, '0', $net, $currency, $description.' revenue');
            if (bccomp($tax, '0', 6) > 0) {
                $this->line($id, $accounts['tax']->id, '0', $tax, $currency, $description.' tax');
            }
        }

        return $id;
    }

    private function business(Company $company, string $type, $date, Request $request, string $idempotency): BusinessTransaction
    {
        return BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => $type, 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $idempotency]);
    }

    private function reverseAccounting(?string $sourceId, Company $company, Request $request, string $businessId, string $description, string $date): string
    {
        if (! $sourceId) {
            throw new RegistryConflictException('The source accounting transaction is missing and cannot be reversed.');
        }
        $id = (string) Str::uuid();
        DB::table('accounting_transactions')->insert(['id' => $id, 'business_transaction_id' => $businessId, 'company_id' => $company->id, 'transaction_type' => 'sales_correction_reversal', 'status' => 'posted', 'business_date' => $date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounting_transaction_lines')->where('accounting_transaction_id', $sourceId)->get()->each(fn ($line) => $this->line($id, $line->account_title_id, (string) $line->credit, (string) $line->debit, (string) $line->currency_code, $description));

        return $id;
    }

    private function line(string $transactionId, string $accountId, string $debit, string $credit, string $currency, string $description): void
    {
        DB::table('accounting_transaction_lines')->insert(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $transactionId, 'account_title_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'currency_code' => strtoupper($currency), 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function returnedQuantity(string $saleLineId, Company $company, ?string $excludeReturnId): string
    {
        return SalesReturnLine::where('company_id', $company->id)->where('sale_line_id', $saleLineId)->whereHas('salesReturn', fn ($q) => $q->whereNotIn('status', ['cancelled', 'reversed'])->when($excludeReturnId, fn ($inner) => $inner->where('id', '<>', $excludeReturnId)))->lockForUpdate()->get()->reduce(fn (string $sum, SalesReturnLine $line) => bcadd($sum, (string) $line->quantity, 6), '0');
    }

    private function refreshSaleReturnStatus(Sale $sale, Company $company): void
    {
        $total = $sale->lines()->get()->reduce(fn (string $sum, SaleLine $line) => bcadd($sum, (string) $line->quantity, 6), '0');
        $returned = $sale->lines()->get()->reduce(fn (string $sum, SaleLine $line) => bcadd($sum, $this->returnedQuantity($line->id, $company, null), 6), '0');
        $sale->return_status = bccomp($returned, '0', 6) === 0 ? 'not_returned' : (bccomp($returned, $total, 6) >= 0 ? 'fully_returned' : 'partially_returned');
        $sale->version++;
        $sale->save();
    }

    private function history(SalesReturn $return, ?string $from, string $to, Company $company, Request $request, ?string $reason = null): void
    {
        SalesReturnStatusHistory::create(['id' => (string) Str::uuid(), 'sales_return_id' => $return->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $return->version ?: 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function adjustmentHistory(SalesAdjustment $adjustment, ?string $from, string $to, Company $company, Request $request, ?string $reason = null): void
    {
        SalesAdjustmentStatusHistory::create(['id' => (string) Str::uuid(), 'sales_adjustment_id' => $adjustment->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $adjustment->version ?: 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function requireStatus(string $actual, string $expected, string $message): void
    {
        if ($actual !== $expected) {
            throw new RegistryConflictException($message, ['status' => $actual]);
        }
    }

    private function assertVersion($record, ?int $version): void
    {
        if ($version !== null && (int) $record->version !== $version) {
            throw new RegistryConflictException('This Sales correction was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
    }

    private function pagination($page): array
    {
        return ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]];
    }
}
