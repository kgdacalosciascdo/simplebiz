<?php

namespace App\Services;

use App\Events\PurchasingLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessTransaction;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PayableEffect;
use App\Models\PayableHoldHistory;
use App\Models\PayableOpenItem;
use App\Models\PaymentTerm;
use App\Models\ProductService;
use App\Models\PurchaseMatchException;
use App\Models\PurchaseMatchHistory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseOrderRevision;
use App\Models\PurchaseOrderStatusHistory;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\PurchaseReturnStatusHistory;
use App\Models\ReferenceCurrency;
use App\Models\StockIssue;
use App\Models\StockIssueLine;
use App\Models\StockLocation;
use App\Models\SupplierAdjustment;
use App\Models\SupplierAdjustmentLine;
use App\Models\SupplierAdjustmentStatusHistory;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceCorrection;
use App\Models\SupplierInvoiceLine;
use App\Models\TaxCode;
use App\Models\Warehouse;
use App\Support\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurchasingService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers, private readonly InventoryService $inventory) {}

    public function lookups(Company $company): array
    {
        return [
            'suppliers' => BusinessPartner::where('company_id', $company->id)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'supplier')->where('status', 'active'))->orderBy('display_name')->get(['id', 'code', 'display_name']),
            'items' => ProductService::where('company_id', $company->id)->where('status', 'active')->where('purchasable', true)->with('baseUnit')->orderBy('name')->get(['id', 'code', 'name', 'record_type', 'base_unit_id', 'stock_managed', 'non_stock', 'standard_purchase_price', 'tax_reference']),
            'currencies' => ReferenceCurrency::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'symbol', 'decimal_precision']),
            'payment_terms' => PaymentTerm::where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name', 'term_type', 'due_days', 'end_of_month']),
            'tax_codes' => TaxCode::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'tax_type', 'rate', 'basis']),
            'warehouses' => Warehouse::where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'stock_locations' => StockLocation::where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get(['id', 'warehouse_id', 'code', 'name']),
        ];
    }

    public function summary(Company $company): array
    {
        $orders = PurchaseOrder::where('company_id', $company->id);
        $invoices = SupplierInvoice::where('company_id', $company->id);
        $payables = PayableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0);
        $returns = PurchaseReturn::where('company_id', $company->id);
        $adjustments = SupplierAdjustment::where('company_id', $company->id);
        $today = Carbon::today($company->timezone ?: config('app.timezone'));
        $dueSoon = (clone $payables)->whereBetween('due_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()]);
        $overdue = (clone $payables)->whereDate('due_date', '<', $today->toDateString());
        $openOrderLines = PurchaseOrderLine::where('company_id', $company->id)->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', ['approved', 'partially_received', 'partially_invoiced']));
        $expectedReceipts = (clone $openOrderLines)->get()->reduce(fn (string $sum, PurchaseOrderLine $line) => bcadd($sum, max('0', bcsub(bcsub((string) $line->quantity, (string) $line->received_quantity, 6), (string) $line->cancelled_quantity, 6)), 6), '0');

        return [
            'orders' => ['total' => (clone $orders)->count(), 'draft' => (clone $orders)->where('status', 'draft')->count(), 'awaiting_approval' => (clone $orders)->whereIn('status', ['submitted', 'awaiting_approval'])->count(), 'approved' => (clone $orders)->whereIn('status', ['approved', 'partially_received', 'partially_invoiced'])->count(), 'open' => (clone $orders)->whereNotIn('status', ['closed', 'cancelled'])->count()],
            'purchases_this_month' => (string) (clone $invoices)->where('status', 'posted')->whereBetween('invoice_date', [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()])->sum('total'),
            'amount_to_pay' => (string) (clone $payables)->sum('remaining_amount'),
            'due_soon' => (string) $dueSoon->sum('remaining_amount'),
            'overdue' => (string) $overdue->sum('remaining_amount'),
            'open_payables' => (clone $payables)->count(),
            'open_purchase_orders' => (clone $orders)->whereNotIn('status', ['closed', 'cancelled'])->count(),
            'invoices_pending_match' => (clone $invoices)->whereIn('status', ['validating', 'exception', 'awaiting_approval'])->count(),
            'returns_pending' => (clone $returns)->whereIn('status', ['draft', 'awaiting_approval', 'approved'])->count(),
            'adjustments_pending' => (clone $adjustments)->whereIn('status', ['draft', 'awaiting_approval'])->count(),
            'payables_on_hold' => (clone $payables)->whereIn('hold_status', ['held', 'disputed'])->count(),
            'goods_receipts_pending_correction' => GoodsReceipt::where('company_id', $company->id)->whereIn('status', ['partially_accepted'])->count(),
            'orders_partially_received' => (clone $orders)->where('status', 'partially_received')->count(),
            'orders_partially_invoiced' => (clone $orders)->where('status', 'partially_invoiced')->count(),
            'orders_overdue_delivery' => (clone $orders)->whereIn('status', ['approved', 'partially_received', 'partially_invoiced'])->whereDate('required_date', '<', $today->toDateString())->count(),
            'expected_receipts_quantity' => $expectedReceipts,
            'supplier_invoice_exceptions' => (clone $invoices)->whereIn('status', ['exception', 'validating'])->count(),
            'payables_due_soon' => (clone $dueSoon)->count(),
            'payables_overdue' => (clone $overdue)->count(),
            'as_of' => now(),
            'recent_activity' => $this->recentActivity($company),
        ];
    }

    public function recentActivity(Company $company): array
    {
        return DB::table('activity_events')->where('company_id', $company->id)->where('event', 'like', 'purchases.%')->latest('occurred_at')->limit(12)->get()->map(fn ($item) => (array) $item)->all();
    }

    public function orders(Company $company, Request $request): array
    {
        $query = PurchaseOrder::where('company_id', $company->id)->with(['supplier', 'currency', 'lines'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->string('supplier_id')))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('order_number', 'ilike', '%'.$request->string('q').'%')->orWhere('supplier_reference', 'ilike', '%'.$request->string('q').'%')->orWhereHas('supplier', fn ($supplier) => $supplier->where('display_name', 'ilike', '%'.$request->string('q').'%'))));
        $page = $query->latest('purchase_date')->latest('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function receipts(Company $company, Request $request): array
    {
        $query = GoodsReceipt::where('company_id', $company->id)->with(['purchaseOrder', 'supplier'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('q'), fn ($q) => $q->where('receipt_number', 'ilike', '%'.$request->string('q').'%'));
        $page = $query->latest('receipt_date')->latest('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function invoices(Company $company, Request $request): array
    {
        $query = SupplierInvoice::where('company_id', $company->id)->with(['supplier', 'currency'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('invoice_number', 'ilike', '%'.$request->string('q').'%')->orWhere('external_invoice_number', 'ilike', '%'.$request->string('q').'%')));
        $page = $query->latest('invoice_date')->latest('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function payables(Company $company, Request $request): array
    {
        $this->refreshPayableStatuses($company);
        $query = PayableOpenItem::where('company_id', $company->id)->with(['supplier', 'currency', 'sourceInvoice'])->where('remaining_amount', '>', 0)->when($request->filled('due_status'), fn ($q) => $q->where('due_status', $request->string('due_status')))->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->string('supplier_id')));
        $page = $query->orderBy('due_date')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function aging(Company $company): array
    {
        $this->refreshPayableStatuses($company);
        $items = PayableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->get();
        $buckets = ['current' => '0', '1_30' => '0', '31_60' => '0', '61_90' => '0', 'over_90' => '0'];
        foreach ($items as $item) {
            $days = $item->due_date ? Carbon::parse($item->due_date)->diffInDays(Carbon::today(), false) : 0;
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90')));
            $buckets[$bucket] = bcadd($buckets[$bucket], (string) $item->remaining_amount, 6);
        }

        return ['buckets' => $buckets, 'items' => $items->load(['supplier', 'currency'])];
    }

    public function returns(Company $company, Request $request): array
    {
        $query = PurchaseReturn::where('company_id', $company->id)->with(['supplier', 'currency', 'purchaseOrder', 'goodsReceipt', 'lines'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('q'), fn ($q) => $q->where('return_number', 'ilike', '%'.$request->string('q').'%'));
        $page = $query->latest('return_date')->latest('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function eligibleReturnLines(Company $company, Request $request): array
    {
        $query = GoodsReceiptLine::where('company_id', $company->id)->whereHas('goodsReceipt', fn ($q) => $q->whereIn('status', ['posted', 'partially_accepted', 'completed']))->with(['goodsReceipt.purchaseOrder', 'purchaseOrderLine']);
        if ($request->filled('goods_receipt_id')) {
            $query->where('goods_receipt_id', $request->string('goods_receipt_id'));
        }
        $lines = $query->get()->map(function (GoodsReceiptLine $line) use ($company) {
            $original = (string) $line->accepted_quantity;
            $returned = $this->returnedQuantity($line->id, $company, null);

            return ['id' => $line->id, 'goods_receipt_id' => $line->goods_receipt_id, 'receipt_number' => $line->goodsReceipt?->receipt_number, 'purchase_order_id' => $line->goodsReceipt?->purchase_order_id, 'purchase_order_number' => $line->goodsReceipt?->purchaseOrder?->order_number, 'purchase_order_line_id' => $line->purchase_order_line_id, 'product_service_id' => $line->product_service_id, 'product_name' => $line->product_name_snapshot, 'warehouse_id' => $line->warehouse_id, 'stock_location_id' => $line->stock_location_id, 'original_received_quantity' => $original, 'previously_returned_quantity' => $returned, 'remaining_returnable_quantity' => bcsub($original, $returned, 6), 'unit_cost' => $this->receiptUnitCost($line), 'stock_managed' => (bool) $line->purchaseOrderLine?->stock_managed_snapshot];
        })->filter(fn (array $line) => bccomp($line['remaining_returnable_quantity'], '0', 6) > 0)->values()->all();

        return ['items' => $lines];
    }

    public function createReturn(array $input, Company $company, Request $request): PurchaseReturn
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $receipt = GoodsReceipt::where('company_id', $company->id)->with(['lines', 'purchaseOrder'])->lockForUpdate()->findOrFail($input['goods_receipt_id']);
            if (! in_array($receipt->status, ['posted', 'partially_accepted', 'completed'], true)) {
                throw new RegistryConflictException('Purchase Returns require a posted Goods Receipt.');
            }
            $order = $receipt->purchaseOrder;
            $return = PurchaseReturn::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'return_number' => $this->numbers->next($company->id, 'purchase_return'), 'supplier_id' => $receipt->supplier_id, 'purchase_order_id' => $receipt->purchase_order_id, 'goods_receipt_id' => $receipt->id, 'currency_id' => $order->currency_id, 'branch_id' => $order->branch_id, 'return_date' => $input['return_date'], 'reason_code_id' => $input['reason_code_id'] ?? null, 'supplier_authorization_reference' => $input['supplier_authorization_reference'] ?? null, 'shipping_reference' => $input['shipping_reference'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'explanation' => $input['explanation'], 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($input['lines'] as $lineInput) {
                $receiptLine = $receipt->lines->firstWhere('id', $lineInput['goods_receipt_line_id']);
                if (! $receiptLine) {
                    throw new RegistryConflictException('Every Purchase Return line must belong to the selected Goods Receipt.');
                }
                $returned = $this->returnedQuantity($receiptLine->id, $company, null);
                $remaining = bcsub((string) $receiptLine->accepted_quantity, $returned, 6);
                if (bccomp((string) $lineInput['quantity'], $remaining, 6) > 0) {
                    throw new RegistryConflictException('The Purchase Return quantity exceeds the remaining returnable received quantity.', ['dependency' => 'returnable_quantity', 'remaining' => $remaining]);
                }
                $poLine = PurchaseOrderLine::where('company_id', $company->id)->findOrFail($receiptLine->purchase_order_line_id);
                if ($poLine->stock_managed_snapshot && (! $receiptLine->warehouse_id || ! $receiptLine->stock_location_id)) {
                    throw new RegistryConflictException('Stock-managed Purchase Returns require the original Warehouse and Stock Location.');
                }
                $unitCost = $this->receiptUnitCost($receiptLine) ?: (string) $poLine->unit_cost;
                PurchaseReturnLine::create(['id' => (string) Str::uuid(), 'purchase_return_id' => $return->id, 'company_id' => $company->id, 'goods_receipt_line_id' => $receiptLine->id, 'purchase_order_line_id' => $poLine->id, 'product_service_id' => $receiptLine->product_service_id, 'unit_of_measure_id' => $receiptLine->unit_of_measure_id, 'warehouse_id' => $receiptLine->warehouse_id, 'stock_location_id' => $receiptLine->stock_location_id, 'original_received_quantity' => $receiptLine->accepted_quantity, 'previously_returned_quantity' => $returned, 'quantity' => $lineInput['quantity'], 'unit_cost' => $unitCost, 'total_cost' => bcmul((string) $lineInput['quantity'], $unitCost, 6), 'stock_managed_snapshot' => (bool) $poLine->stock_managed_snapshot, 'condition' => $lineInput['condition'] ?? 'returned', 'reason' => $lineInput['reason'] ?? $input['explanation'], 'product_code_snapshot' => $receiptLine->product_code_snapshot, 'product_name_snapshot' => $receiptLine->product_name_snapshot, 'unit_code_snapshot' => $receiptLine->unit_code_snapshot, 'unit_name_snapshot' => $receiptLine->unit_name_snapshot]);
            }
            $this->audit->record($request, 'purchases.return.created', $return, $company->id, [], $return->toArray(), null, 'Purchase Return created', 'A Purchase Return draft was created from an accepted Goods Receipt.');

            return $return->fresh(['lines', 'supplier', 'currency', 'purchaseOrder', 'goodsReceipt']);
        });
    }

    public function transitionReturn(PurchaseReturn $return, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $action, $company, $request, $reason, $version) {
            $return = PurchaseReturn::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($return->id);
            $this->assertVersion($return, ['version' => $version]);
            $from = $return->status;
            $actor = $request->user()?->id;
            if ($action === 'submit') {
                if ($from !== 'draft') {
                    throw new RegistryConflictException('Only Draft Purchase Returns can be submitted.');
                }
                $return->status = 'awaiting_approval';
                $return->submitted_by = $actor;
                $return->submitted_at = now();
            } elseif ($action === 'review') {
                if ($from !== 'awaiting_approval') {
                    throw new RegistryConflictException('Only Purchase Returns awaiting approval can be reviewed.');
                }
            } elseif ($action === 'approve') {
                if (! in_array($from, ['draft', 'awaiting_approval'], true)) {
                    throw new RegistryConflictException('Only Draft or awaiting-approval Purchase Returns can be approved.');
                }
                if ($actor && $actor === $return->created_by) {
                    throw new RegistryConflictException('The preparer cannot approve the same Purchase Return.');
                }
                $return->status = 'approved';
                $return->approved_by = $actor;
                $return->approved_at = now();
            } elseif ($action === 'post') {
                $this->postReturn($return, $company, $request);
            } elseif ($action === 'reverse') {
                $this->reverseReturn($return, $company, $request, $reason);
            } elseif ($action === 'cancel') {
                if (! in_array($from, ['draft', 'awaiting_approval', 'approved'], true) || ! $reason) {
                    throw new RegistryConflictException('A cancellable Purchase Return and reason are required.');
                }
                $return->status = 'cancelled';
            } else {
                throw new RegistryConflictException('Unsupported Purchase Return action.');
            }
            if ($return->status !== $from) {
                $return->version++;
                $return->save();
                PurchaseReturnStatusHistory::create(['id' => (string) Str::uuid(), 'purchase_return_id' => $return->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => $return->status, 'reason' => $reason, 'actor_id' => $actor, 'version' => $return->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
            }
            $this->audit->record($request, 'purchases.return.'.$action, $return, $company->id, ['status' => $from], ['status' => $return->status], $reason, 'Purchase Return '.$action, 'Purchase Return lifecycle action completed.');

            return $return->fresh(['lines', 'supplier', 'currency', 'purchaseOrder', 'goodsReceipt']);
        });
    }

    public function adjustments(Company $company, Request $request): array
    {
        $query = SupplierAdjustment::where('company_id', $company->id)->with(['supplier', 'currency', 'lines'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->string('supplier_id')));
        $page = $query->latest('adjustment_date')->latest('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function invoiceCorrections(Company $company, Request $request): array
    {
        $query = SupplierInvoiceCorrection::where('company_id', $company->id)->with(['supplier', 'currency', 'originalInvoice'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        $page = $query->latest('correction_date')->latest('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), $this->pagination($page)];
    }

    public function createAdjustment(array $input, Company $company, Request $request): SupplierAdjustment
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $invoice = ! empty($input['supplier_invoice_id']) ? SupplierInvoice::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($input['supplier_invoice_id']) : null;
            $return = ! empty($input['purchase_return_id']) ? PurchaseReturn::where('company_id', $company->id)->lockForUpdate()->findOrFail($input['purchase_return_id']) : null;
            if (($invoice === null) === ($return === null)) {
                throw new RegistryConflictException('A Supplier Adjustment must reference exactly one Supplier Invoice or Purchase Return.');
            }
            if ($invoice && $invoice->status !== 'posted') {
                throw new RegistryConflictException('Supplier Adjustments require a Posted Supplier Invoice.');
            }
            if ($return && $return->status !== 'posted') {
                throw new RegistryConflictException('Supplier Adjustments require a Posted Purchase Return.');
            }
            if ($return && $input['adjustment_type'] !== 'debit') {
                throw new RegistryConflictException('A Purchase Return may only create a Supplier Debit Adjustment.');
            }
            $supplierId = $invoice?->supplier_id ?? $return->supplier_id;
            $currencyId = $invoice?->currency_id ?? $return->currency_id;
            if ((string) $currencyId !== (string) $input['currency_id']) {
                throw new RegistryConflictException('The adjustment currency must match its source document.');
            }
            $adjustment = SupplierAdjustment::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'adjustment_number' => $this->numbers->next($company->id, 'supplier_adjustment'), 'adjustment_type' => $input['adjustment_type'], 'supplier_id' => $supplierId, 'currency_id' => $currencyId, 'supplier_invoice_id' => $invoice?->id, 'purchase_return_id' => $return?->id, 'branch_id' => $invoice?->branch_id ?? $return?->branch_id, 'adjustment_date' => $input['adjustment_date'], 'reason_code_id' => $input['reason_code_id'] ?? null, 'external_reference' => $input['external_reference'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'explanation' => $input['explanation'], 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $amount = '0';
            $tax = '0';
            foreach ($input['lines'] as $line) {
                $lineAmount = ! empty($line['quantity']) ? bcmul((string) $line['quantity'], (string) $line['unit_amount'], 6) : (string) $line['unit_amount'];
                $lineTax = (string) ($line['tax_amount'] ?? 0);
                $sourceLine = null;
                if (! empty($line['supplier_invoice_line_id'])) {
                    $sourceLine = $invoice?->lines->firstWhere('id', $line['supplier_invoice_line_id']);
                    if (! $sourceLine) {
                        throw new RegistryConflictException('Adjustment line is outside the selected Supplier Invoice.');
                    }
                }
                if (! empty($line['purchase_return_line_id']) && (! $return || ! $return->lines()->whereKey($line['purchase_return_line_id'])->exists())) {
                    throw new RegistryConflictException('Adjustment line is outside the selected Purchase Return.');
                }
                SupplierAdjustmentLine::create(['id' => (string) Str::uuid(), 'supplier_adjustment_id' => $adjustment->id, 'company_id' => $company->id, 'supplier_invoice_line_id' => $sourceLine?->id, 'purchase_return_line_id' => $line['purchase_return_line_id'] ?? null, 'product_service_id' => $line['product_service_id'] ?? $sourceLine?->product_service_id, 'description' => $line['description'], 'quantity' => $line['quantity'] ?? null, 'unit_amount' => $line['unit_amount'], 'amount' => $lineAmount, 'tax_amount' => $lineTax, 'total_amount' => bcadd($lineAmount, $lineTax, 6), 'product_code_snapshot' => $sourceLine?->product_code_snapshot, 'product_name_snapshot' => $sourceLine?->product_name_snapshot]);
                $amount = bcadd($amount, $lineAmount, 6);
                $tax = bcadd($tax, $lineTax, 6);
            }
            $adjustment->update(['amount' => $amount, 'tax_amount' => $tax, 'total_amount' => bcadd($amount, $tax, 6)]);
            $this->audit->record($request, 'purchases.adjustment.created', $adjustment, $company->id, [], $adjustment->toArray(), null, 'Supplier Adjustment created', 'A Supplier Adjustment draft was created.');

            return $adjustment->fresh(['lines', 'supplier', 'currency']);
        });
    }

    public function transitionAdjustment(SupplierAdjustment $adjustment, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): SupplierAdjustment
    {
        return DB::transaction(function () use ($adjustment, $action, $company, $request, $reason, $version) {
            $adjustment = SupplierAdjustment::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($adjustment->id);
            $this->assertVersion($adjustment, ['version' => $version]);
            $from = $adjustment->status;
            $actor = $request->user()?->id;
            if ($action === 'submit') {
                if ($from !== 'draft') {
                    throw new RegistryConflictException('Only Draft Supplier Adjustments can be submitted.');
                }
                $adjustment->status = 'awaiting_approval';
                $adjustment->submitted_by = $actor;
                $adjustment->submitted_at = now();
            } elseif (in_array($action, ['approve', 'post'], true)) {
                if ($from !== 'awaiting_approval') {
                    throw new RegistryConflictException('Only Supplier Adjustments awaiting approval can be posted.');
                }
                $this->postAdjustment($adjustment, $company, $request);
            } elseif ($action === 'reverse') {
                $this->reverseAdjustment($adjustment, $company, $request, $reason);
            } elseif ($action === 'cancel') {
                if (! in_array($from, ['draft', 'awaiting_approval'], true) || ! $reason) {
                    throw new RegistryConflictException('A cancellable Supplier Adjustment and reason are required.');
                }
                $adjustment->status = 'cancelled';
            } else {
                throw new RegistryConflictException('Unsupported Supplier Adjustment action.');
            }
            if ($adjustment->status !== $from) {
                $adjustment->version++;
                $adjustment->save();
                SupplierAdjustmentStatusHistory::create(['id' => (string) Str::uuid(), 'supplier_adjustment_id' => $adjustment->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => $adjustment->status, 'reason' => $reason, 'actor_id' => $actor, 'version' => $adjustment->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
            }
            $this->audit->record($request, 'purchases.adjustment.'.$action, $adjustment, $company->id, ['status' => $from], ['status' => $adjustment->status], $reason, 'Supplier Adjustment '.$action, 'Supplier Adjustment lifecycle action completed.');

            return $adjustment->fresh(['lines', 'supplier', 'currency']);
        });
    }

    public function createInvoiceCorrection(SupplierInvoice $invoice, array $input, Company $company, Request $request): SupplierInvoiceCorrection
    {
        return DB::transaction(function () use ($invoice, $input, $company, $request) {
            $invoice = SupplierInvoice::where('company_id', $company->id)->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'posted' || (float) $invoice->paid_amount > 0) {
                throw new RegistryConflictException('Only an unpaid Posted Supplier Invoice can be corrected.');
            }
            if (SupplierInvoiceCorrection::where('company_id', $company->id)->where('original_supplier_invoice_id', $invoice->id)->whereIn('status', ['draft', 'awaiting_approval', 'posted'])->exists()) {
                throw new RegistryConflictException('This Supplier Invoice already has an active correction.');
            }
            $this->assertPostingDate($input['correction_date'], $company);
            $correction = SupplierInvoiceCorrection::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'correction_number' => $this->numbers->next($company->id, 'invoice_correction'), 'correction_type' => 'reversal', 'original_supplier_invoice_id' => $invoice->id, 'supplier_id' => $invoice->supplier_id, 'currency_id' => $invoice->currency_id, 'correction_date' => $input['correction_date'], 'reason' => $input['reason'], 'evidence_reference' => $input['evidence_reference'] ?? null, 'amount' => $invoice->subtotal, 'tax_amount' => $invoice->tax_total, 'total_amount' => $invoice->total, 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $this->audit->record($request, 'purchases.invoice-correction.created', $correction, $company->id, [], $correction->toArray(), $input['reason'], 'Supplier Invoice correction created', 'A governed Supplier Invoice correction was created.');

            return $correction->fresh(['originalInvoice', 'supplier', 'currency']);
        });
    }

    public function transitionInvoiceCorrection(SupplierInvoiceCorrection $correction, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): SupplierInvoiceCorrection
    {
        return DB::transaction(function () use ($correction, $action, $company, $request, $reason, $version) {
            $correction = SupplierInvoiceCorrection::where('company_id', $company->id)->lockForUpdate()->findOrFail($correction->id);
            $this->assertVersion($correction, ['version' => $version]);
            if ($action === 'submit') {
                if ($correction->status !== 'draft') {
                    throw new RegistryConflictException('Only Draft Supplier Invoice Corrections can be submitted.');
                }
                $correction->status = 'awaiting_approval';
                $correction->submitted_by = $request->user()?->id;
                $correction->submitted_at = now();
                $correction->version++;
                $correction->save();
            } elseif ($action === 'post' || $action === 'approve') {
                if (! in_array($correction->status, ['draft', 'awaiting_approval'], true)) {
                    throw new RegistryConflictException('Only pending Supplier Invoice Corrections can be posted.');
                }
                $this->postInvoiceCorrection($correction, $company, $request);
            } elseif ($action === 'reverse') {
                $this->reverseInvoiceCorrection($correction, $company, $request, $reason);
            } else {
                throw new RegistryConflictException('Unsupported Supplier Invoice Correction action.');
            }

            return $correction->fresh(['originalInvoice', 'supplier', 'currency']);
        });
    }

    public function resolveMatchException(PurchaseMatchException $exception, array $input, Company $company, Request $request): PurchaseMatchException
    {
        return DB::transaction(function () use ($exception, $input, $company, $request) {
            $exception = PurchaseMatchException::where('company_id', $company->id)->lockForUpdate()->findOrFail($exception->id);
            if ($exception->status !== 'open') {
                throw new RegistryConflictException('Only open matching exceptions can be resolved.');
            }
            $exception->status = 'resolved';
            $exception->resolution = $input['resolution'];
            $exception->resolved_by = $request->user()?->id;
            $exception->resolved_at = now();
            $exception->save();
            $invoice = SupplierInvoice::where('company_id', $company->id)->with('matchExceptions')->findOrFail($exception->supplier_invoice_id);
            if (! $invoice->matchExceptions->contains(fn ($item) => $item->status === 'open')) {
                $invoice->status = 'awaiting_approval';
                $invoice->match_status = 'matched';
                $invoice->match_exception = null;
                $invoice->version++;
                $invoice->save();
                PurchaseMatchHistory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'supplier_invoice_id' => $invoice->id, 'match_method' => $invoice->purchase_order_id ? 'three_way' : 'no_order', 'status' => 'resolved', 'tolerance_amount' => $invoice->match_tolerance_amount ?? 0, 'variance_amount' => $this->matchVariance($invoice->load('lines')), 'summary' => 'All matching exceptions were resolved.', 'exceptions_snapshot' => [], 'actor_id' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
            }
            $this->audit->record($request, 'purchases.match-exception.resolved', $exception, $company->id, ['status' => 'open'], $exception->toArray(), $input['resolution'], 'Purchase match exception resolved', 'The matching exception was resolved with history preserved.');

            return $exception->fresh();
        });
    }

    public function payableEffects(PayableOpenItem $payable, Company $company): array
    {
        $payable->load(['effects.currency', 'holdHistory']);

        return ['effects' => $payable->effects, 'hold_history' => $payable->holdHistory];
    }

    public function holdPayable(PayableOpenItem $payable, string $action, string $reason, Company $company, Request $request, ?int $version = null): PayableOpenItem
    {
        return DB::transaction(function () use ($payable, $action, $reason, $company, $request, $version) {
            $payable = PayableOpenItem::where('company_id', $company->id)->lockForUpdate()->findOrFail($payable->id);
            $this->assertVersion($payable, ['version' => $version]);
            if ($payable->remaining_amount <= 0) {
                throw new RegistryConflictException('A settled or reversed payable cannot be placed on hold.');
            }
            $from = $payable->hold_status;
            $to = match ($action) {
                'hold' => 'held', 'dispute' => 'disputed', 'release' => 'not_held', default => throw new RegistryConflictException('Unsupported payable hold action.')
            };
            if ($from === $to) {
                return $payable;
            }
            $payable->hold_status = $to;
            $payable->hold_reason = $to === 'not_held' ? null : $reason;
            $payable->version++;
            $payable->save();
            PayableHoldHistory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payable_open_item_id' => $payable->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $payable->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $this->audit->record($request, 'purchases.payable.'.$action, $payable, $company->id, ['hold_status' => $from], ['hold_status' => $to], $reason, 'Payable '.$action, 'Payable payment readiness was updated without creating a payment.');

            return $payable->fresh(['supplier', 'currency', 'sourceInvoice']);
        });
    }

    public function ledger(string $supplierId, Company $company, Request $request): array
    {
        $this->supplier($company, $supplierId);
        $entries = collect();
        SupplierInvoice::where('company_id', $company->id)->where('supplier_id', $supplierId)->whereIn('status', ['posted', 'reversed'])->with('currency')->get()->each(fn ($invoice) => $entries->push(['date' => $invoice->invoice_date?->toDateString(), 'type' => 'supplier_invoice', 'document_id' => $invoice->id, 'document_number' => $invoice->invoice_number, 'amount' => (string) $invoice->total, 'currency' => $invoice->currency?->code, 'status' => $invoice->status]));
        SupplierAdjustment::where('company_id', $company->id)->where('supplier_id', $supplierId)->whereIn('status', ['posted', 'applied', 'reversed'])->with('currency')->get()->each(fn ($adjustment) => $entries->push(['date' => $adjustment->adjustment_date?->toDateString(), 'type' => 'supplier_'.$adjustment->adjustment_type.'_adjustment', 'document_id' => $adjustment->id, 'document_number' => $adjustment->adjustment_number, 'amount' => (string) ($adjustment->adjustment_type === 'debit' ? '-'.$adjustment->total_amount : $adjustment->total_amount), 'currency' => $adjustment->currency?->code, 'status' => $adjustment->status]));

        return ['supplier_id' => $supplierId, 'entries' => $entries->sortByDesc('date')->values()->all()];
    }

    public function attention(Company $company): array
    {
        return ['items' => [
            ['key' => 'orders.awaiting_approval', 'count' => PurchaseOrder::where('company_id', $company->id)->where('status', 'awaiting_approval')->count(), 'title' => 'Purchase Orders awaiting approval'],
            ['key' => 'invoices.matching', 'count' => SupplierInvoice::where('company_id', $company->id)->whereIn('status', ['exception', 'awaiting_approval'])->count(), 'title' => 'Supplier Invoice matching review'],
            ['key' => 'returns.awaiting_approval', 'count' => PurchaseReturn::where('company_id', $company->id)->where('status', 'awaiting_approval')->count(), 'title' => 'Purchase Returns awaiting approval'],
            ['key' => 'adjustments.awaiting_approval', 'count' => SupplierAdjustment::where('company_id', $company->id)->where('status', 'awaiting_approval')->count(), 'title' => 'Supplier Adjustments awaiting approval'],
            ['key' => 'payables.hold', 'count' => PayableOpenItem::where('company_id', $company->id)->whereIn('hold_status', ['held', 'disputed'])->where('remaining_amount', '>', 0)->count(), 'title' => 'Payables on hold or dispute'],
            ['key' => 'orders.overdue_delivery', 'count' => PurchaseOrder::where('company_id', $company->id)->whereIn('status', ['approved', 'partially_received', 'partially_invoiced'])->whereDate('required_date', '<', Carbon::today($company->timezone ?: config('app.timezone'))->toDateString())->count(), 'title' => 'Purchase deliveries overdue'],
            ['key' => 'receipts.pending_correction', 'count' => GoodsReceipt::where('company_id', $company->id)->where('status', 'partially_accepted')->count(), 'title' => 'Goods Receipts pending correction'],
        ]];
    }

    public function reports(string $report, Company $company, Request $request): array
    {
        $from = $request->input('from');
        $to = $request->input('to');
        $filterDate = fn ($query, string $column) => $query->when($from, fn ($q) => $q->whereDate($column, '>=', $from))->when($to, fn ($q) => $q->whereDate($column, '<=', $to));
        $data = match ($report) {
            'purchase-register' => $filterDate(PurchaseOrder::where('company_id', $company->id)->with('supplier'), 'purchase_date')->latest('purchase_date')->get(),
            'purchase-order-register' => $filterDate(PurchaseOrder::where('company_id', $company->id)->with('supplier'), 'purchase_date')->latest('purchase_date')->get(),
            'goods-receipt-register' => $filterDate(GoodsReceipt::where('company_id', $company->id)->with('supplier'), 'receipt_date')->latest('receipt_date')->get(),
            'purchase-return-register' => $filterDate(PurchaseReturn::where('company_id', $company->id)->with('supplier'), 'return_date')->latest('return_date')->get(),
            'supplier-invoice-register' => $filterDate(SupplierInvoice::where('company_id', $company->id)->with('supplier', 'currency'), 'invoice_date')->latest('invoice_date')->get(),
            'supplier-adjustment-register' => $filterDate(SupplierAdjustment::where('company_id', $company->id)->with('supplier', 'currency'), 'adjustment_date')->latest('adjustment_date')->get(),
            'outstanding-payables', 'payables-aging' => PayableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->with(['supplier', 'currency'])->orderBy('due_date')->get(),
            'matching-exceptions' => PurchaseMatchException::where('company_id', $company->id)->with('invoice')->latest()->get(),
            default => throw new RegistryConflictException('The requested Purchases report is not supported.'),
        };

        return ['report' => $report, 'as_of' => now(), 'currency_context' => 'Amounts remain separated by source currency.', 'data' => $data];
    }

    public function amendOrder(PurchaseOrder $order, array $input, Company $company, Request $request): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $input, $company, $request) {
            $order = PurchaseOrder::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($order->id);
            if (in_array($order->status, ['closed', 'cancelled', 'fully_received', 'fully_invoiced'], true) || $order->lines->contains(fn ($line) => bccomp((string) $line->received_quantity, '0', 6) > 0 || bccomp((string) $line->invoiced_quantity, '0', 6) > 0)) {
                throw new RegistryConflictException('Only an unreceived and uninvoiced Purchase Order can be amended.');
            }
            $reason = $input['reason'] ?? null;
            if (! $reason) {
                throw new RegistryConflictException('A Purchase Order amendment reason is required.');
            }
            $before = ['order' => $order->toArray(), 'lines' => $order->lines->toArray()];
            $supplier = $this->supplier($company, $input['supplier_id']);
            $currency = $this->currency($company, $input['currency_id']);
            $term = ! empty($input['payment_term_id']) ? $this->term($company, $input['payment_term_id']) : null;
            $order->fill(['supplier_id' => $supplier->id, 'branch_id' => $input['branch_id'] ?? null, 'currency_id' => $currency->id, 'payment_term_id' => $term?->id, 'purchase_date' => $input['purchase_date'], 'required_date' => $input['required_date'] ?? null, 'supplier_reference' => $input['supplier_reference'] ?? null, 'notes' => $input['notes'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'status' => 'awaiting_approval', 'approved_by' => null, 'approved_at' => null]);
            $order->version++;
            $order->save();
            $order->lines()->delete();
            $this->replaceOrderLines($order, $input['lines'], $company, $currency);
            $this->recalculateOrder($order);
            PurchaseOrderRevision::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'purchase_order_id' => $order->id, 'revision_number' => (int) $order->version, 'revision_type' => 'amendment', 'reason' => $reason, 'before_snapshot' => $before, 'after_snapshot' => ['order' => $order->fresh()->toArray(), 'lines' => $order->lines()->get()->toArray()], 'actor_id' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $this->syncIncoming($company);
            $this->audit->record($request, 'purchases.order.amended', $order, $company->id, $before, $order->fresh()->toArray(), $reason, 'Purchase Order amended', 'The Purchase Order was amended with a new approval requirement.');

            return $order->fresh(['lines', 'supplier', 'currency', 'paymentTerm', 'revisions']);
        });
    }

    public function reopenOrder(PurchaseOrder $order, string $reason, Company $company, Request $request, ?int $version = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason, $company, $request, $version) {
            $order = PurchaseOrder::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($order->id);
            $this->assertVersion($order, ['version' => $version]);
            if ($order->status !== 'closed' || ! $reason || $order->lines->contains(fn ($line) => bccomp((string) $line->invoiced_quantity, (string) $line->quantity, 6) >= 0)) {
                throw new RegistryConflictException('Only a closed Purchase Order with remaining uninvoiced activity can be reopened with a reason.');
            }
            $from = $order->status;
            $order->status = 'approved';
            $order->closed_by = null;
            $order->closed_at = null;
            $order->version++;
            $order->save();
            PurchaseOrderStatusHistory::create(['id' => (string) Str::uuid(), 'purchase_order_id' => $order->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => 'approved', 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $order->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $this->syncIncoming($company);
            $this->audit->record($request, 'purchases.order.reopened', $order, $company->id, ['status' => $from], ['status' => 'approved'], $reason, 'Purchase Order reopened', 'Remaining Purchase Order activity was reopened.');

            return $order->fresh(['lines', 'supplier', 'currency', 'paymentTerm', 'statusHistory']);
        });
    }

    public function createOrder(array $input, Company $company, Request $request): PurchaseOrder
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $supplier = $this->supplier($company, $input['supplier_id']);
            $currency = $this->currency($company, $input['currency_id']);
            $term = ! empty($input['payment_term_id']) ? $this->term($company, $input['payment_term_id']) : null;
            $order = PurchaseOrder::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'order_number' => $this->numbers->next($company->id, 'purchase_order'), 'purchase_type' => $input['purchase_type'] ?? 'purchase_order', 'supplier_id' => $supplier->id, 'branch_id' => $input['branch_id'] ?? null, 'currency_id' => $currency->id, 'payment_term_id' => $term?->id, 'purchase_date' => $input['purchase_date'], 'required_date' => $input['required_date'] ?? null, 'supplier_reference' => $input['supplier_reference'] ?? null, 'notes' => $input['notes'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $this->replaceOrderLines($order, $input['lines'], $company, $currency);
            $this->recalculateOrder($order);
            $this->audit->record($request, 'purchases.order.created', $order, $company->id, [], $order->toArray(), null, 'Purchase Order created', 'A Purchase Order draft was created.');

            return $order->fresh(['lines', 'supplier', 'currency', 'paymentTerm']);
        });
    }

    public function updateOrder(PurchaseOrder $order, array $input, Company $company, Request $request): PurchaseOrder
    {
        if ($order->status !== 'draft') {
            throw new RegistryConflictException('Only Draft Purchase Orders can be edited.', ['status' => $order->status]);
        }
        $this->assertVersion($order, $input);

        return DB::transaction(function () use ($order, $input, $company, $request) {
            $order = PurchaseOrder::where('company_id', $company->id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $supplier = $this->supplier($company, $input['supplier_id']);
            $currency = $this->currency($company, $input['currency_id']);
            $term = ! empty($input['payment_term_id']) ? $this->term($company, $input['payment_term_id']) : null;
            $before = $order->toArray();
            $order->fill(['supplier_id' => $supplier->id, 'branch_id' => $input['branch_id'] ?? null, 'currency_id' => $currency->id, 'payment_term_id' => $term?->id, 'purchase_date' => $input['purchase_date'], 'required_date' => $input['required_date'] ?? null, 'supplier_reference' => $input['supplier_reference'] ?? null, 'notes' => $input['notes'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null]);
            $order->version++;
            $order->save();
            $this->replaceOrderLines($order, $input['lines'], $company, $currency);
            $this->recalculateOrder($order);
            $this->audit->record($request, 'purchases.order.updated', $order, $company->id, $before, $order->toArray(), null, 'Purchase Order updated', 'A Purchase Order draft was updated.');

            return $order->fresh(['lines', 'supplier', 'currency', 'paymentTerm']);
        });
    }

    public function transitionOrder(PurchaseOrder $order, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $action, $company, $request, $reason, $version) {
            $order = PurchaseOrder::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($order->id);
            if ($version !== null && (int) $order->version !== $version) {
                throw new RegistryConflictException('This Purchase Order was changed by another user. Refresh and try again.', ['version_conflict' => true]);
            }
            $from = $order->status;
            $actor = $request->user()?->id;
            $to = match ($action) {
                'submit' => $this->setOrderStatus($order, 'draft', 'submitted', $actor),
                'review' => $this->setOrderStatus($order, 'submitted', 'awaiting_approval', $actor),
                'approve' => $this->approveOrder($order, $actor),
                'cancel' => $this->cancelOrder($order, $actor, $reason),
                'close' => $this->closeOrder($order, $actor),
                default => throw new RegistryConflictException('Unsupported Purchase Order action.'),
            };
            if ($to !== $from) {
                PurchaseOrderStatusHistory::create(['id' => (string) Str::uuid(), 'purchase_order_id' => $order->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $actor, 'version' => $order->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
            }
            $this->syncIncoming($company);
            $this->audit->record($request, 'purchases.order.'.$action, $order, $company->id, ['status' => $from], ['status' => $to], $reason, 'Purchase Order '.$action, 'Purchase Order lifecycle action completed.');

            return $order->fresh(['lines', 'supplier', 'currency', 'paymentTerm', 'statusHistory']);
        });
    }

    public function createReceipt(array $input, Company $company, Request $request): GoodsReceipt
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $order = PurchaseOrder::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($input['purchase_order_id']);
            if (! in_array($order->status, ['approved', 'partially_received', 'partially_invoiced'], true)) {
                throw new RegistryConflictException('Goods Receipts require an Approved or open partially received Purchase Order.', ['status' => $order->status]);
            }
            $supplier = $this->supplier($company, $order->supplier_id);
            $receipt = GoodsReceipt::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_number' => $this->numbers->next($company->id, 'goods_receipt'), 'purchase_order_id' => $order->id, 'supplier_id' => $supplier->id, 'receipt_date' => $input['receipt_date'], 'supplier_delivery_reference' => $input['supplier_delivery_reference'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'explanation' => $input['explanation'] ?? null, 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($input['lines'] as $lineInput) {
                $poLine = $order->lines->firstWhere('id', $lineInput['purchase_order_line_id']);
                if (! $poLine) {
                    throw new RegistryConflictException('Every Goods Receipt line must belong to the selected Purchase Order.', ['dependency' => 'purchase_order_line']);
                }
                $this->validateReceiptQuantities($poLine, $lineInput);
                $warehouseId = $lineInput['warehouse_id'] ?? $poLine->warehouse_id;
                $locationId = $lineInput['stock_location_id'] ?? $poLine->stock_location_id;
                if ($poLine->stock_managed_snapshot && (! $warehouseId || ! $locationId)) {
                    throw new RegistryConflictException('Stock-managed receiving requires a Warehouse and Stock Location.', ['dependency' => 'inventory_location']);
                }
                GoodsReceiptLine::create(['id' => (string) Str::uuid(), 'goods_receipt_id' => $receipt->id, 'company_id' => $company->id, 'purchase_order_line_id' => $poLine->id, 'product_service_id' => $poLine->product_service_id, 'unit_of_measure_id' => $poLine->unit_of_measure_id, 'warehouse_id' => $warehouseId, 'stock_location_id' => $locationId, 'quantity' => $lineInput['quantity'], 'accepted_quantity' => $this->acceptedQuantity($lineInput), 'rejected_quantity' => $lineInput['rejected_quantity'] ?? 0, 'damaged_quantity' => $lineInput['damaged_quantity'] ?? 0, 'short_quantity' => $this->shortQuantity($poLine, $lineInput['quantity']), 'over_quantity' => $this->overQuantity($poLine, $lineInput['quantity']), 'backordered_quantity' => $this->shortQuantity($poLine, $lineInput['quantity']), 'reason' => $lineInput['reason'] ?? null, 'product_code_snapshot' => $poLine->product_code_snapshot, 'product_name_snapshot' => $poLine->product_name_snapshot, 'unit_code_snapshot' => $poLine->unit_code_snapshot, 'unit_name_snapshot' => $poLine->unit_name_snapshot]);
            }
            $this->audit->record($request, 'purchases.receipt.created', $receipt, $company->id, [], $receipt->toArray(), null, 'Goods Receipt created', 'A Goods Receipt draft was created.');

            return $receipt->fresh(['lines', 'purchaseOrder', 'supplier']);
        });
    }

    public function transitionReceipt(GoodsReceipt $receipt, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $action, $company, $request, $reason, $version) {
            $receipt = GoodsReceipt::where('company_id', $company->id)->with(['lines.purchaseOrderLine', 'purchaseOrder.lines'])->lockForUpdate()->findOrFail($receipt->id);
            if ($version !== null && (int) $receipt->version !== $version) {
                throw new RegistryConflictException('This Goods Receipt was changed by another user. Refresh and try again.', ['version_conflict' => true]);
            }
            if ($action === 'submit') {
                if ($receipt->status !== 'draft') {
                    throw new RegistryConflictException('Only Draft Goods Receipts can be submitted.');
                }
                $receipt->status = 'validating';
                $receipt->submitted_by = $request->user()?->id;
                $receipt->submitted_at = now();
                $receipt->version++;
                $receipt->save();
            } elseif ($action === 'post') {
                $this->postReceipt($receipt, $company, $request);
            } elseif ($action === 'reverse') {
                $this->reverseReceipt($receipt, $company, $request, $reason);
            } else {
                throw new RegistryConflictException('Unsupported Goods Receipt action.');
            }

            return $receipt->fresh(['lines', 'purchaseOrder', 'supplier']);
        });
    }

    public function createInvoice(array $input, Company $company, Request $request): SupplierInvoice
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $supplier = $this->supplier($company, $input['supplier_id']);
            $currency = $this->currency($company, $input['currency_id']);
            $term = ! empty($input['payment_term_id']) ? $this->term($company, $input['payment_term_id']) : null;
            if (SupplierInvoice::where('company_id', $company->id)->where('supplier_id', $supplier->id)->where('external_invoice_number', $input['external_invoice_number'])->exists()) {
                throw new RegistryConflictException('This supplier invoice reference already exists for this supplier.', ['duplicate' => true]);
            }
            $po = ! empty($input['purchase_order_id']) ? PurchaseOrder::where('company_id', $company->id)->with('lines')->findOrFail($input['purchase_order_id']) : null;
            if ($po && (string) $po->supplier_id !== (string) $supplier->id) {
                throw new RegistryConflictException('The Supplier Invoice supplier must match the Purchase Order supplier.');
            }
            $invoice = SupplierInvoice::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'invoice_number' => $this->numbers->next($company->id, 'supplier_invoice'), 'supplier_id' => $supplier->id, 'purchase_order_id' => $po?->id, 'currency_id' => $currency->id, 'payment_term_id' => $term?->id, 'branch_id' => $input['branch_id'] ?? null, 'external_invoice_number' => $input['external_invoice_number'], 'invoice_date' => $input['invoice_date'], 'received_date' => $input['received_date'] ?? null, 'due_date' => $this->dueDate($input['invoice_date'], $term, $input['due_date'] ?? null, $company), 'evidence_reference' => $input['evidence_reference'] ?? null, 'notes' => $input['notes'] ?? null, 'match_tolerance_amount' => $input['match_tolerance_amount'] ?? 0, 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $this->replaceInvoiceLines($invoice, $input['lines'], $company, $supplier, $po);
            $this->recalculateInvoice($invoice);
            $this->audit->record($request, 'purchases.invoice.created', $invoice, $company->id, [], $invoice->toArray(), null, 'Supplier Invoice created', 'A Supplier Invoice draft was created.');

            return $invoice->fresh(['lines', 'supplier', 'currency', 'purchaseOrder']);
        });
    }

    public function transitionInvoice(SupplierInvoice $invoice, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice, $action, $company, $request, $reason, $version) {
            $invoice = SupplierInvoice::where('company_id', $company->id)->with(['lines.purchaseOrderLine', 'lines.goodsReceiptLine', 'supplier', 'currency'])->lockForUpdate()->findOrFail($invoice->id);
            if ($version !== null && (int) $invoice->version !== $version) {
                throw new RegistryConflictException('This Supplier Invoice was changed by another user. Refresh and try again.', ['version_conflict' => true]);
            }
            match ($action) {
                'submit' => $this->submitInvoice($invoice, $company, $request),
                'approve' => $this->approveInvoice($invoice, $company, $request),
                'post' => $this->postInvoice($invoice, $company, $request),
                'reverse' => $this->reverseInvoice($invoice, $company, $request, $reason),
                default => throw new RegistryConflictException('Unsupported Supplier Invoice action.'),
            };

            return $invoice->fresh(['lines', 'supplier', 'currency', 'purchaseOrder', 'payable', 'matchExceptions']);
        });
    }

    private function postReceipt(GoodsReceipt $receipt, Company $company, Request $request): void
    {
        if ($receipt->status !== 'validating') {
            throw new RegistryConflictException('Only Validating Goods Receipts can be posted.', ['status' => $receipt->status]);
        }
        $order = PurchaseOrder::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($receipt->purchase_order_id);
        $stockLines = [];
        foreach ($receipt->lines as $line) {
            $poLine = $order->lines->firstWhere('id', $line->purchase_order_line_id);
            if (! $poLine) {
                throw new RegistryConflictException('Goods Receipt line is outside the Purchase Order scope.');
            }
            $remaining = bcsub((string) $poLine->quantity, (string) $poLine->received_quantity, 6);
            if (bccomp((string) $line->accepted_quantity, $remaining, 6) > 0) {
                throw new RegistryConflictException('Goods Receipt quantity exceeds the remaining Purchase Order quantity.', ['dependency' => 'over_receipt', 'purchase_order_line_id' => $poLine->id]);
            }
            $poLine->received_quantity = bcadd((string) $poLine->received_quantity, (string) $line->accepted_quantity, 6);
            $poLine->save();
            if ($poLine->stock_managed_snapshot && bccomp((string) $line->accepted_quantity, '0', 6) > 0) {
                $stockLines[] = ['receipt_line' => $line, 'po_line' => $poLine];
            }
        }
        if ($stockLines) {
            $stockReceipt = $this->inventory->createReceipt(['source_type' => 'purchase_goods_receipt', 'source_reference' => $receipt->receipt_number, 'business_date' => $receipt->receipt_date, 'explanation' => 'Stock received under '.$receipt->receipt_number, 'lines' => array_map(fn ($item) => ['product_service_id' => $item['po_line']->product_service_id, 'warehouse_id' => $item['receipt_line']->warehouse_id, 'stock_location_id' => $item['receipt_line']->stock_location_id, 'quantity' => $item['receipt_line']->accepted_quantity], $stockLines)], $company, $request);
            foreach ($stockReceipt->lines as $index => $stockLine) {
                $source = $stockLines[$index];
                $stockLine->unit_cost = $source['po_line']->unit_cost;
                $stockLine->total_cost = bcmul((string) $source['po_line']->unit_cost, (string) $stockLine->quantity, 6);
                $stockLine->currency_code = $order->currency()->value('code');
                $stockLine->cost_source = 'purchase_order';
                $stockLine->save();
            }
            $sourceMap = [];
            foreach ($stockReceipt->lines as $index => $stockLine) {
                $sourceMap[$stockLine->id] = $stockLines[$index]['receipt_line']->id;
            }
            $postedStockReceipt = $this->inventory->postPurchaseReceipt($stockReceipt, $company, $request, GoodsReceipt::class, $receipt->id, $sourceMap);
            foreach ($postedStockReceipt->lines as $index => $stockLine) {
                $receiptLine = $stockLines[$index]['receipt_line'];
                $receiptLine->stock_receipt_id = $postedStockReceipt->id;
                $receiptLine->stock_receipt_line_id = $stockLine->id;
                $receiptLine->inventory_movement_id = $stockLine->movement_id;
                $receiptLine->save();
            }
        }
        $receipt->status = $receipt->lines->contains(fn ($line) => bccomp((string) $line->accepted_quantity, bcsub((string) $line->quantity, bcadd((string) $line->rejected_quantity, (string) $line->damaged_quantity, 6), 6), 6) !== 0) ? 'partially_accepted' : 'completed';
        $receipt->posted_by = $request->user()?->id;
        $receipt->posted_at = now();
        $receipt->version++;
        $receipt->save();
        $this->refreshOrderStatus($order);
        $this->syncIncoming($company);
        $this->audit->record($request, 'purchases.receipt.posted', $receipt, $company->id, [], $receipt->toArray(), null, 'Goods Receipt posted', 'Accepted stock and receiving evidence were posted.');
    }

    private function reverseReceipt(GoodsReceipt $receipt, Company $company, Request $request, ?string $reason): void
    {
        if (! in_array($receipt->status, ['posted', 'partially_accepted', 'completed'], true)) {
            throw new RegistryConflictException('Only Posted Goods Receipts can be reversed.');
        }
        if (! $reason) {
            throw new RegistryConflictException('A reversal reason is required.');
        }
        foreach ($receipt->lines->pluck('stock_receipt_id')->filter()->unique() as $stockReceiptId) {
            $this->inventory->reverseDocument('receipt', (string) $stockReceiptId, $company, $request);
        }
        $order = PurchaseOrder::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($receipt->purchase_order_id);
        foreach ($receipt->lines as $line) {
            $poLine = $order->lines->firstWhere('id', $line->purchase_order_line_id);
            if ($poLine) {
                $poLine->received_quantity = bcsub((string) $poLine->received_quantity, (string) $line->accepted_quantity, 6);
                $poLine->save();
            }
        }
        $receipt->status = 'reversed';
        $receipt->reversed_by = $request->user()?->id;
        $receipt->reversed_at = now();
        $receipt->reversal_reason = $reason;
        $receipt->version++;
        $receipt->save();
        $this->refreshOrderStatus($order);
        $this->syncIncoming($company);
        $this->audit->record($request, 'purchases.receipt.reversed', $receipt, $company->id, [], $receipt->toArray(), $reason, 'Goods Receipt reversed', 'The Goods Receipt and linked stock effects were reversed.');
    }

    private function postReturn(PurchaseReturn $return, Company $company, Request $request): void
    {
        if ($return->status !== 'approved') {
            throw new RegistryConflictException('Only Approved Purchase Returns can be posted.', ['status' => $return->status]);
        }
        $return->load(['lines', 'goodsReceipt']);
        $stockIssue = StockIssue::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'document_number' => $this->numbers->next($company->id, 'stock_issue'), 'source_type' => 'purchase_return', 'source_reference' => $return->return_number, 'business_date' => $return->return_date, 'explanation' => 'Stock returned under '.$return->return_number, 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => 'purchase-return:'.$return->id]);
        $sourceMap = [];
        $costs = [];
        foreach ($return->lines as $line) {
            if (! $line->stock_managed_snapshot) {
                continue;
            }
            $issueLine = StockIssueLine::create(['id' => (string) Str::uuid(), 'stock_issue_id' => $stockIssue->id, 'company_id' => $company->id, 'product_service_id' => $line->product_service_id, 'warehouse_id' => $line->warehouse_id, 'stock_location_id' => $line->stock_location_id, 'unit_of_measure_id' => $line->unit_of_measure_id, 'quantity' => $line->quantity, 'product_code_snapshot' => $line->product_code_snapshot, 'product_name_snapshot' => $line->product_name_snapshot, 'unit_code_snapshot' => $line->unit_code_snapshot, 'unit_name_snapshot' => $line->unit_name_snapshot]);
            $sourceMap[$issueLine->id] = $line->id;
            $costs[$line->id] = ['unit_cost' => $line->unit_cost, 'total_cost' => $line->total_cost, 'currency_code' => $return->currency()->value('code'), 'cost_source' => 'purchase_return'];
        }
        if ($sourceMap) {
            $posted = $this->inventory->postPurchaseReturn($stockIssue, $company, $request, PurchaseReturn::class, $return->id, $sourceMap, $costs);
            foreach ($posted->lines as $issueLine) {
                $returnLineId = $sourceMap[$issueLine->id];
                $returnLine = $return->lines->firstWhere('id', $returnLineId);
                $returnLine->stock_issue_id = $posted->id;
                $returnLine->stock_issue_line_id = $issueLine->id;
                $returnLine->inventory_movement_id = $issueLine->movement_id;
                $returnLine->save();
            }
        } else {
            $stockIssue->status = 'posted';
            $stockIssue->posted_by = $request->user()?->id;
            $stockIssue->posted_at = now();
            $stockIssue->save();
        }
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'purchase_return', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $return->return_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => 'purchase-return:'.$return->id]);
        if (! SupplierAdjustment::where('company_id', $company->id)->where('purchase_return_id', $return->id)->exists()) {
            $total = $return->lines->reduce(fn (string $sum, PurchaseReturnLine $line) => bcadd($sum, (string) $line->total_cost, 6), '0');
            SupplierAdjustment::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'adjustment_number' => $this->numbers->next($company->id, 'supplier_adjustment'), 'adjustment_type' => 'debit', 'supplier_id' => $return->supplier_id, 'currency_id' => $return->currency_id, 'purchase_return_id' => $return->id, 'branch_id' => $return->branch_id, 'adjustment_date' => $return->return_date, 'evidence_reference' => $return->evidence_reference, 'explanation' => 'Supplier credit eligibility created from '.$return->return_number, 'amount' => $total, 'tax_amount' => 0, 'total_amount' => $total, 'status' => 'eligible', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        }
        $return->status = 'posted';
        $return->business_transaction_id = $business->id;
        $return->posted_by = $request->user()?->id;
        $return->posted_at = now();
        $return->save();
        PurchasingLifecycleEvent::dispatch('EVT-PUR-012', $company->id, PurchaseReturn::class, $return->id, $request->user()?->id, $request->attributes->get('correlation_id'));
    }

    private function reverseReturn(PurchaseReturn $return, Company $company, Request $request, ?string $reason): void
    {
        if ($return->status === 'reversed') {
            return;
        }
        if ($return->status !== 'posted' || ! $reason) {
            throw new RegistryConflictException('Only Posted Purchase Returns can be reversed and a reason is required.');
        }
        $return->load('lines');
        foreach ($return->lines->pluck('stock_issue_id')->filter()->unique() as $issueId) {
            $this->inventory->reverseDocument('issue', (string) $issueId, $company, $request);
        }
        SupplierAdjustment::where('company_id', $company->id)->where('purchase_return_id', $return->id)->where('status', 'eligible')->update(['status' => 'reversed', 'version' => DB::raw('version + 1')]);
        $return->status = 'reversed';
        $return->reversed_by = $request->user()?->id;
        $return->reversed_at = now();
        $return->reversal_reason = $reason;
        $return->save();
        PurchasingLifecycleEvent::dispatch('EVT-PUR-018-RETURN', $company->id, PurchaseReturn::class, $return->id, $request->user()?->id, $request->attributes->get('correlation_id'));
    }

    private function postAdjustment(SupplierAdjustment $adjustment, Company $company, Request $request): void
    {
        $adjustment->load(['lines', 'supplierInvoice', 'purchaseReturn']);
        $invoice = $adjustment->supplierInvoice;
        $payable = $invoice ? PayableOpenItem::where('company_id', $company->id)->where('source_supplier_invoice_id', $invoice->id)->lockForUpdate()->first() : null;
        if ($invoice && ! $payable) {
            throw new RegistryConflictException('The source Supplier Invoice has no payable open item.');
        }
        $amount = (string) $adjustment->total_amount;
        $accounts = $invoice ? $this->payableAccounts($company, $invoice->load('lines')) : null;
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'supplier_adjustment', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $adjustment->adjustment_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => 'supplier-adjustment:'.$adjustment->id]);
        $accountingId = null;
        if ($accounts) {
            $accountingId = $this->createAdjustmentAccounting($adjustment, $accounts, $company, $request, $business->id);
        }
        if ($payable) {
            $delta = $adjustment->adjustment_type === 'debit' ? '-'.$amount : $amount;
            $this->recordPayableEffect($payable, SupplierAdjustment::class, $adjustment->id, 'supplier_adjustment_posted', $delta, $adjustment->currency_id, 'Supplier '.$adjustment->adjustment_type.' adjustment '.$adjustment->adjustment_number, $business->id, $accountingId, $request, $company);
        }
        $adjustment->status = 'posted';
        $adjustment->business_transaction_id = $business->id;
        $adjustment->accounting_transaction_id = $accountingId;
        $adjustment->posted_by = $request->user()?->id;
        $adjustment->posted_at = now();
        $adjustment->save();
        PurchasingLifecycleEvent::dispatch('EVT-PUR-'.strtoupper($adjustment->adjustment_type === 'debit' ? '013' : '014'), $company->id, SupplierAdjustment::class, $adjustment->id, $request->user()?->id, $request->attributes->get('correlation_id'));
    }

    private function reverseAdjustment(SupplierAdjustment $adjustment, Company $company, Request $request, ?string $reason): void
    {
        if ($adjustment->status === 'reversed') {
            return;
        }
        if ($adjustment->status !== 'posted' || ! $reason) {
            throw new RegistryConflictException('Only Posted Supplier Adjustments can be reversed and a reason is required.');
        }
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'supplier_adjustment_reversal', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => now()->toDateString(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => 'supplier-adjustment-reversal:'.$adjustment->id]);
        $accountingId = $adjustment->accounting_transaction_id ? $this->reverseAccountingTransaction($adjustment->accounting_transaction_id, $company, $request, $business->id, 'Reversal of Supplier Adjustment '.$adjustment->adjustment_number, now()->toDateString()) : null;
        $effect = PayableEffect::where('company_id', $company->id)->where('source_type', SupplierAdjustment::class)->where('source_id', $adjustment->id)->where('effect_type', 'supplier_adjustment_posted')->lockForUpdate()->first();
        if ($effect) {
            $payable = $effect->payable()->lockForUpdate()->firstOrFail();
            $this->recordPayableEffect($payable, SupplierAdjustment::class, $adjustment->id, 'supplier_adjustment_reversed', bcmul((string) $effect->amount_delta, '-1', 6), $effect->currency_id, 'Reversal of Supplier Adjustment '.$adjustment->adjustment_number, $business->id, $accountingId, $request, $company, $effect->id);
        }
        $adjustment->status = 'reversed';
        $adjustment->reversed_by = $request->user()?->id;
        $adjustment->reversed_at = now();
        $adjustment->reversal_reason = $reason;
        $adjustment->save();
        PurchasingLifecycleEvent::dispatch('EVT-PUR-015', $company->id, SupplierAdjustment::class, $adjustment->id, $request->user()?->id, $request->attributes->get('correlation_id'));
    }

    private function submitInvoice(SupplierInvoice $invoice, Company $company, Request $request): string
    {
        if ($invoice->status !== 'draft') {
            throw new RegistryConflictException('Only Draft Supplier Invoices can be submitted.');
        }
        $exceptions = $this->invoiceExceptions($invoice, $company);
        if ($exceptions) {
            foreach ($exceptions as $exception) {
                PurchaseMatchException::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'supplier_invoice_id' => $invoice->id, 'supplier_invoice_line_id' => $exception['line_id'] ?? null, 'exception_type' => $exception['type'], 'status' => 'open', 'expected_amount' => $exception['expected'] ?? null, 'actual_amount' => $exception['actual'] ?? null, 'explanation' => $exception['message']]);
            }
            $invoice->status = 'exception';
            $invoice->match_status = 'exception';
            $invoice->match_exception = implode('; ', array_column($exceptions, 'message'));
        } else {
            $invoice->status = 'awaiting_approval';
            $invoice->match_status = $invoice->purchase_order_id ? 'matched' : 'not_required';
            $invoice->match_exception = null;
        }
        $invoice->submitted_by = $request->user()?->id;
        $invoice->submitted_at = now();
        $invoice->version++;
        $invoice->save();
        PurchaseMatchHistory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'supplier_invoice_id' => $invoice->id, 'match_method' => $invoice->purchase_order_id ? 'three_way' : 'no_order', 'status' => $invoice->status === 'exception' ? 'exception' : 'matched', 'tolerance_amount' => $invoice->match_tolerance_amount ?? 0, 'variance_amount' => $this->matchVariance($invoice), 'summary' => $invoice->match_exception, 'exceptions_snapshot' => $exceptions, 'actor_id' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $this->audit->record($request, 'purchases.invoice.submit', $invoice, $company->id, [], $invoice->toArray(), null, 'Supplier Invoice submitted', 'Supplier Invoice matching and approval review started.');

        return $invoice->status;
    }

    private function approveInvoice(SupplierInvoice $invoice, Company $company, Request $request): string
    {
        if (! in_array($invoice->status, ['awaiting_approval', 'exception'], true)) {
            throw new RegistryConflictException('Only Supplier Invoices awaiting approval or with an authorized exception can be approved.');
        }
        if ($invoice->status === 'exception' && ! $request->user()?->hasPermission('purchases.match.override', $company->id)) {
            throw new RegistryConflictException('A match exception requires the Purchases match override permission.', ['dependency' => 'purchases.match.override']);
        }
        $invoice->status = 'matched';
        $invoice->approved_by = $request->user()?->id;
        $invoice->approved_at = now();
        $invoice->match_status = 'matched';
        $invoice->version++;
        $invoice->save();
        PurchaseMatchHistory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'supplier_invoice_id' => $invoice->id, 'match_method' => $invoice->purchase_order_id ? 'three_way' : 'no_order', 'status' => 'approved', 'tolerance_amount' => $invoice->match_tolerance_amount ?? 0, 'variance_amount' => $this->matchVariance($invoice), 'summary' => 'Matching exception approved by an authorized user.', 'exceptions_snapshot' => $invoice->matchExceptions()->where('status', 'open')->get()->map(fn ($item) => $item->toArray())->all(), 'actor_id' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);

        return $invoice->status;
    }

    private function postInvoice(SupplierInvoice $invoice, Company $company, Request $request): void
    {
        if ($invoice->status !== 'matched') {
            throw new RegistryConflictException('Only Matched Supplier Invoices can be posted.', ['status' => $invoice->status]);
        }
        if ($invoice->payable_open_item_id) {
            return;
        }
        $accounts = $this->payableAccounts($company, $invoice);
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'supplier_invoice', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $invoice->invoice_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => 'supplier-invoice:'.$invoice->id]);
        $accountingId = (string) Str::uuid();
        DB::table('accounting_transactions')->insert(['id' => $accountingId, 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => 'supplier_invoice', 'status' => 'posted', 'business_date' => $invoice->invoice_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
        $stockTotal = '0';
        $expenseTotal = '0';
        foreach ($invoice->lines as $line) {
            if ($line->stock_managed_snapshot) {
                $stockTotal = bcadd($stockTotal, (string) $line->taxable_amount, 6);
            } else {
                $expenseTotal = bcadd($expenseTotal, (string) $line->taxable_amount, 6);
            }
        }
        if (bccomp($stockTotal, '0', 6) > 0) {
            $this->accountingLine($accountingId, $accounts['inventory']->id, $stockTotal, '0', $invoice->currency->code, 'Inventory received for '.$invoice->invoice_number);
        }
        if (bccomp($expenseTotal, '0', 6) > 0) {
            $this->accountingLine($accountingId, $accounts['expense']->id, $expenseTotal, '0', $invoice->currency->code, 'Purchase expense for '.$invoice->invoice_number);
        }
        if (bccomp((string) $invoice->tax_total, '0', 6) > 0) {
            $this->accountingLine($accountingId, $accounts['tax']->id, $invoice->tax_total, '0', $invoice->currency->code, 'Recoverable purchase tax for '.$invoice->invoice_number);
        }
        $this->accountingLine($accountingId, $accounts['payable']->id, '0', $invoice->total, $invoice->currency->code, 'Accounts payable for '.$invoice->invoice_number);
        $payable = PayableOpenItem::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'supplier_id' => $invoice->supplier_id, 'branch_id' => $invoice->branch_id, 'source_supplier_invoice_id' => $invoice->id, 'source_document_number' => $invoice->invoice_number, 'currency_id' => $invoice->currency_id, 'original_amount' => $invoice->total, 'paid_amount' => 0, 'remaining_amount' => 0, 'due_date' => $invoice->due_date, 'settlement_status' => 'unpaid', 'due_status' => $this->dueStatus($invoice->due_date, $company), 'last_calculated_at' => now(), 'version' => 1]);
        $this->recordPayableEffect($payable, SupplierInvoice::class, $invoice->id, 'invoice_posted', $invoice->total, $invoice->currency_id, 'Original Supplier Invoice obligation', $business->id, $accountingId, $request, $company);
        foreach ($invoice->lines as $line) {
            if ($line->purchase_order_line_id) {
                $poLine = PurchaseOrderLine::where('company_id', $company->id)->lockForUpdate()->find($line->purchase_order_line_id);
                if ($poLine) {
                    $remaining = bcsub(bcsub((string) $poLine->quantity, (string) $poLine->invoiced_quantity, 6), (string) $poLine->cancelled_quantity, 6);
                    if (bccomp((string) $line->quantity, $remaining, 6) > 0) {
                        throw new RegistryConflictException('Supplier Invoice quantity exceeds the remaining Purchase Order quantity.', ['dependency' => 'over_invoicing', 'remaining' => $remaining]);
                    }
                    $poLine->invoiced_quantity = bcadd((string) $poLine->invoiced_quantity, (string) $line->quantity, 6);
                    $poLine->save();
                    $this->refreshOrderStatus($poLine->purchaseOrder()->with('lines')->first());
                }
            }
        }
        $invoice->status = 'posted';
        $invoice->posted_by = $request->user()?->id;
        $invoice->posted_at = now();
        $invoice->business_transaction_id = $business->id;
        $invoice->accounting_transaction_id = $accountingId;
        $invoice->payable_open_item_id = $payable->id;
        $invoice->remaining_amount = $invoice->total;
        $invoice->version++;
        $invoice->save();
        $this->audit->record($request, 'purchases.invoice.posted', $invoice, $company->id, [], $invoice->toArray(), null, 'Supplier Invoice posted', 'A payable was posted without creating a payment or cash movement.');
    }

    private function postInvoiceCorrection(SupplierInvoiceCorrection $correction, Company $company, Request $request): void
    {
        $original = SupplierInvoice::where('company_id', $company->id)->with(['currency', 'payable'])->lockForUpdate()->findOrFail($correction->original_supplier_invoice_id);
        if ($original->status !== 'posted' || (float) $original->paid_amount > 0) {
            throw new RegistryConflictException('Only an unpaid Posted Supplier Invoice can be corrected.');
        }
        $this->assertPostingDate($correction->correction_date?->toDateString() ?? now()->toDateString(), $company);
        $payable = PayableOpenItem::where('company_id', $company->id)->where('source_supplier_invoice_id', $original->id)->lockForUpdate()->firstOrFail();
        if ($payable->paid_amount > 0) {
            throw new RegistryConflictException('A Supplier Invoice with payment allocation cannot be reversed before the MDS-500 payment correction workflow exists.');
        }
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'supplier_invoice_reversal', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $correction->correction_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => 'supplier-invoice-correction:'.$correction->id]);
        $accountingId = $this->reverseAccountingTransaction($original->accounting_transaction_id, $company, $request, $business->id, 'Supplier Invoice reversal '.$original->invoice_number, $correction->correction_date);
        $effect = $this->recordPayableEffect($payable, SupplierInvoiceCorrection::class, $correction->id, 'invoice_reversal_posted', '-'.(string) $original->total, $original->currency_id, 'Reversal of Supplier Invoice '.$original->invoice_number, $business->id, $accountingId, $request, $company);
        foreach ($original->load('lines')->lines as $line) {
            if (! $line->purchase_order_line_id) {
                continue;
            }
            $poLine = PurchaseOrderLine::where('company_id', $company->id)->lockForUpdate()->find($line->purchase_order_line_id);
            if ($poLine) {
                $poLine->invoiced_quantity = bcsub((string) $poLine->invoiced_quantity, (string) $line->quantity, 6);
                $poLine->save();
                $this->refreshOrderStatus($poLine->purchaseOrder()->with('lines')->first());
            }
        }
        $original->status = 'reversed';
        $original->reversed_by = $request->user()?->id;
        $original->reversed_at = now();
        $original->reversal_reason = $correction->reason;
        $original->remaining_amount = 0;
        $original->version++;
        $original->save();
        $correction->status = 'posted';
        $correction->business_transaction_id = $business->id;
        $correction->accounting_transaction_id = $accountingId;
        $correction->posted_by = $request->user()?->id;
        $correction->posted_at = now();
        $correction->version++;
        $correction->save();
        PurchasingLifecycleEvent::dispatch('EVT-PUR-018', $company->id, SupplierInvoiceCorrection::class, $correction->id, $request->user()?->id, $request->attributes->get('correlation_id'));
    }

    private function reverseInvoiceCorrection(SupplierInvoiceCorrection $correction, Company $company, Request $request, ?string $reason): void
    {
        if ($correction->status !== 'posted' || ! $reason) {
            throw new RegistryConflictException('Only Posted Supplier Invoice Corrections can be reversed and a reason is required.');
        }
        $payable = PayableOpenItem::where('company_id', $company->id)->where('source_supplier_invoice_id', $correction->original_supplier_invoice_id)->lockForUpdate()->firstOrFail();
        $effect = PayableEffect::where('company_id', $company->id)->where('source_type', SupplierInvoiceCorrection::class)->where('source_id', $correction->id)->where('effect_type', 'invoice_reversal_posted')->firstOrFail();
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'supplier_invoice_correction_reversal', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => now()->toDateString(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => 'supplier-invoice-correction-reversal:'.$correction->id]);
        $accountingId = $this->reverseAccountingTransaction($correction->accounting_transaction_id, $company, $request, $business->id, 'Reversal of Supplier Invoice correction '.$correction->correction_number, now()->toDateString());
        $this->recordPayableEffect($payable, SupplierInvoiceCorrection::class, $correction->id, 'invoice_reversal_correction_reversed', bcmul((string) $effect->amount_delta, '-1', 6), $effect->currency_id, 'Reversal of Supplier Invoice correction '.$correction->correction_number, $business->id, $accountingId, $request, $company, $effect->id);
        $original = SupplierInvoice::where('company_id', $company->id)->with('lines')->lockForUpdate()->findOrFail($correction->original_supplier_invoice_id);
        foreach ($original->lines as $line) {
            if (! $line->purchase_order_line_id) {
                continue;
            }
            $poLine = PurchaseOrderLine::where('company_id', $company->id)->lockForUpdate()->find($line->purchase_order_line_id);
            if ($poLine) {
                $poLine->invoiced_quantity = bcadd((string) $poLine->invoiced_quantity, (string) $line->quantity, 6);
                $poLine->save();
                $this->refreshOrderStatus($poLine->purchaseOrder()->with('lines')->first());
            }
        }
        $original->status = 'posted';
        $original->reversed_by = null;
        $original->reversed_at = null;
        $original->reversal_reason = null;
        $original->remaining_amount = $original->total;
        $original->version++;
        $original->save();
        $correction->status = 'reversed';
        $correction->reversed_by = $request->user()?->id;
        $correction->reversed_at = now();
        $correction->reversal_reason = $reason;
        $correction->version++;
        $correction->save();
    }

    private function createAdjustmentAccounting(SupplierAdjustment $adjustment, array $accounts, Company $company, Request $request, string $businessId): string
    {
        $accountingId = (string) Str::uuid();
        DB::table('accounting_transactions')->insert(['id' => $accountingId, 'business_transaction_id' => $businessId, 'company_id' => $company->id, 'transaction_type' => 'supplier_adjustment', 'status' => 'posted', 'business_date' => $adjustment->adjustment_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
        $amount = (string) $adjustment->total_amount;
        $currency = $adjustment->currency()->value('code');
        $sourceInvoice = $adjustment->supplierInvoice;
        $debitAccount = $accounts['payable'];
        $creditAccount = $accounts['expense'];
        if ($sourceInvoice && $sourceInvoice->lines->contains(fn ($line) => $line->stock_managed_snapshot)) {
            $creditAccount = $accounts['inventory'];
        }
        if ($adjustment->adjustment_type === 'debit') {
            $this->accountingLine($accountingId, $debitAccount->id, $amount, '0', $currency, 'Supplier debit adjustment '.$adjustment->adjustment_number);
            $this->accountingLine($accountingId, $creditAccount->id, '0', $amount, $currency, 'Supplier debit adjustment offset '.$adjustment->adjustment_number);
        } else {
            $this->accountingLine($accountingId, $creditAccount->id, $amount, '0', $currency, 'Supplier credit adjustment offset '.$adjustment->adjustment_number);
            $this->accountingLine($accountingId, $debitAccount->id, '0', $amount, $currency, 'Supplier credit adjustment '.$adjustment->adjustment_number);
        }

        return $accountingId;
    }

    private function reverseAccountingTransaction(?string $sourceAccountingId, Company $company, Request $request, string $businessId, string $description, string $date): string
    {
        if (! $sourceAccountingId) {
            throw new RegistryConflictException('The source accounting transaction is missing and cannot be reversed.');
        }
        $accountingId = (string) Str::uuid();
        DB::table('accounting_transactions')->insert(['id' => $accountingId, 'business_transaction_id' => $businessId, 'company_id' => $company->id, 'transaction_type' => 'purchases_reversal', 'status' => 'posted', 'business_date' => $date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounting_transaction_lines')->where('accounting_transaction_id', $sourceAccountingId)->get()->each(fn ($line) => $this->accountingLine($accountingId, $line->account_title_id, (string) $line->credit, (string) $line->debit, (string) $line->currency_code, $description));

        return $accountingId;
    }

    private function recordPayableEffect(PayableOpenItem $payable, string $sourceType, string $sourceId, string $effectType, string $delta, string $currencyId, string $description, ?string $businessId, ?string $accountingId, Request $request, Company $company, ?string $reversalEffectId = null): PayableEffect
    {
        $existing = PayableEffect::where('company_id', $company->id)->where('source_type', $sourceType)->where('source_id', $sourceId)->where('effect_type', $effectType)->first();
        if ($existing) {
            return $existing;
        }
        $newRemaining = bcadd((string) $payable->remaining_amount, $delta, 6);
        if (bccomp($newRemaining, '0', 6) < 0) {
            throw new RegistryConflictException('The payable correction exceeds the remaining outstanding amount.', ['dependency' => 'payable_balance']);
        }
        $effect = PayableEffect::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payable_open_item_id' => $payable->id, 'source_type' => $sourceType, 'source_id' => $sourceId, 'effect_type' => $effectType, 'amount_delta' => $delta, 'currency_id' => $currencyId, 'description' => $description, 'created_by' => $request->user()?->id, 'business_transaction_id' => $businessId, 'accounting_transaction_id' => $accountingId, 'reversal_effect_id' => $reversalEffectId]);
        $payable->remaining_amount = $newRemaining;
        $payable->settlement_status = bccomp($newRemaining, '0', 6) === 0 ? 'settled' : ($payable->paid_amount > 0 ? 'partially_paid' : 'unpaid');
        $payable->version++;
        $payable->last_calculated_at = now();
        $payable->save();

        return $effect;
    }

    private function returnedQuantity(string $goodsReceiptLineId, Company $company, ?string $excludeReturnId): string
    {
        return PurchaseReturnLine::where('company_id', $company->id)->where('goods_receipt_line_id', $goodsReceiptLineId)->whereHas('purchaseReturn', fn ($q) => $q->whereNotIn('status', ['cancelled', 'reversed'])->when($excludeReturnId, fn ($inner) => $inner->where('id', '<>', $excludeReturnId)))->lockForUpdate()->get()->reduce(fn (string $sum, PurchaseReturnLine $line) => bcadd($sum, (string) $line->quantity, 6), '0');
    }

    private function receiptUnitCost(GoodsReceiptLine $line): string
    {
        if ($line->stock_receipt_line_id) {
            return (string) (DB::table('stock_receipt_lines')->where('id', $line->stock_receipt_line_id)->value('unit_cost') ?? '0');
        }

        return '0';
    }

    private function assertPostingDate(string $date, Company $company): void
    {
        if ($company->cash_movement_lock_date && $date <= $company->cash_movement_lock_date->toDateString()) {
            throw new RegistryConflictException('The business date is within the locked date range.', ['dependency' => 'lock_date']);
        }
        if ($company->opening_balance_lock_date && $date <= $company->opening_balance_lock_date->toDateString()) {
            throw new RegistryConflictException('The business date is within the locked date range.', ['dependency' => 'lock_date']);
        }
    }

    private function reverseInvoice(SupplierInvoice $invoice, Company $company, Request $request, ?string $reason): void
    {
        if ($invoice->status !== 'posted') {
            throw new RegistryConflictException('Only Posted Supplier Invoices can be reversed.', ['status' => $invoice->status]);
        }
        if (! $reason) {
            throw new RegistryConflictException('A reversal reason is required.');
        }
        if ((float) $invoice->paid_amount > 0 || SupplierInvoiceCorrection::where('company_id', $company->id)->where('original_supplier_invoice_id', $invoice->id)->where('status', 'posted')->exists()) {
            throw new RegistryConflictException('This Supplier Invoice has an incompatible downstream settlement or correction and cannot be reversed.');
        }
        $correction = SupplierInvoiceCorrection::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'correction_number' => $this->numbers->next($company->id, 'invoice_correction'), 'correction_type' => 'reversal', 'original_supplier_invoice_id' => $invoice->id, 'supplier_id' => $invoice->supplier_id, 'currency_id' => $invoice->currency_id, 'correction_date' => now()->toDateString(), 'reason' => $reason, 'evidence_reference' => $invoice->evidence_reference, 'amount' => $invoice->subtotal, 'tax_amount' => $invoice->tax_total, 'total_amount' => $invoice->total, 'status' => 'draft', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
        $this->postInvoiceCorrection($correction, $company, $request);
    }

    private function replaceOrderLines(PurchaseOrder $order, array $lines, Company $company, ReferenceCurrency $currency): void
    {
        $order->lines()->delete();
        foreach ($lines as $input) {
            $product = $this->product($company, $input['product_service_id']);
            $unit = $product->baseUnit;
            if (! $unit || (! empty($input['unit_of_measure_id']) && (string) $input['unit_of_measure_id'] !== (string) $unit->id)) {
                throw new RegistryConflictException('Purchases must use the Product base Unit of Measure.', ['dependency' => 'unit_of_measure']);
            }
            $this->inventory->validateSaleLineLocation($company, $product, $input);
            if ($product->stock_managed && (empty($input['warehouse_id']) || empty($input['stock_location_id']))) {
                throw new RegistryConflictException('Stock-managed Purchase Order lines require a Warehouse and Stock Location.', ['dependency' => 'inventory_location']);
            }
            $calculated = $this->calculateLine($input, $product, $company);
            PurchaseOrderLine::create(['id' => (string) Str::uuid(), 'purchase_order_id' => $order->id, 'company_id' => $company->id, 'product_service_id' => $product->id, 'unit_of_measure_id' => $unit->id, 'warehouse_id' => $input['warehouse_id'] ?? null, 'stock_location_id' => $input['stock_location_id'] ?? null, 'tax_code_id' => $calculated['tax']?->id, 'description' => $input['description'] ?? $product->name, 'quantity' => $input['quantity'], 'unit_cost' => $input['unit_cost'], 'discount_type' => $input['discount_type'] ?? null, 'discount_value' => $input['discount_value'] ?? 0, ...$calculated['amounts'], 'stock_managed_snapshot' => (bool) $product->stock_managed, 'non_stock_snapshot' => (bool) $product->non_stock, 'product_code_snapshot' => $product->code, 'product_name_snapshot' => $product->name, 'unit_code_snapshot' => $unit->code, 'unit_name_snapshot' => $unit->name, 'tax_code_snapshot' => $calculated['tax']?->code, 'tax_rate' => $calculated['rate'], 'tax_basis' => $calculated['basis']]);
        }
    }

    private function replaceInvoiceLines(SupplierInvoice $invoice, array $lines, Company $company, BusinessPartner $supplier, ?PurchaseOrder $po): void
    {
        foreach ($lines as $input) {
            $product = $this->product($company, $input['product_service_id']);
            $unit = $product->baseUnit;
            if (! $unit || (! empty($input['unit_of_measure_id']) && (string) $input['unit_of_measure_id'] !== (string) $unit->id)) {
                throw new RegistryConflictException('Supplier Invoice lines must use the Product base Unit of Measure.');
            }
            $poLine = null;
            if (! empty($input['purchase_order_line_id'])) {
                $poLine = $po?->lines->firstWhere('id', $input['purchase_order_line_id']) ?? PurchaseOrderLine::where('company_id', $company->id)->whereKey($input['purchase_order_line_id'])->first();
                if (! $poLine || (string) $poLine->purchaseOrder->supplier_id !== (string) $supplier->id) {
                    throw new RegistryConflictException('Supplier Invoice line is outside the selected supplier Purchase Order.');
                }
            }
            $grLine = null;
            if (! empty($input['goods_receipt_line_id'])) {
                $grLine = GoodsReceiptLine::where('company_id', $company->id)->with(['goodsReceipt.purchaseOrder'])->whereKey($input['goods_receipt_line_id'])->first();
                if (! $grLine || (string) $grLine->goodsReceipt->supplier_id !== (string) $supplier->id) {
                    throw new RegistryConflictException('Supplier Invoice line is outside the selected supplier Goods Receipt.');
                }
            }
            $calculated = $this->calculateLine($input, $product, $company);
            SupplierInvoiceLine::create(['id' => (string) Str::uuid(), 'supplier_invoice_id' => $invoice->id, 'company_id' => $company->id, 'product_service_id' => $product->id, 'unit_of_measure_id' => $unit->id, 'tax_code_id' => $calculated['tax']?->id, 'purchase_order_line_id' => $poLine?->id, 'goods_receipt_line_id' => $grLine?->id, 'description' => $input['description'] ?? $product->name, 'quantity' => $input['quantity'], 'unit_cost' => $input['unit_cost'], ...$calculated['amounts'], 'stock_managed_snapshot' => (bool) $product->stock_managed, 'product_code_snapshot' => $product->code, 'product_name_snapshot' => $product->name, 'unit_code_snapshot' => $unit->code, 'unit_name_snapshot' => $unit->name, 'tax_code_snapshot' => $calculated['tax']?->code, 'tax_rate' => $calculated['rate'], 'tax_basis' => $calculated['basis']]);
        }
    }

    private function recalculateOrder(PurchaseOrder $order): void
    {
        $lines = $order->lines()->get();
        $order->subtotal = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->gross_amount, 6), '0');
        $order->line_discount_total = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->discount_amount, 6), '0');
        $order->taxable_amount = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->taxable_amount, 6), '0');
        $order->tax_total = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->tax_amount, 6), '0');
        $order->total = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->net_amount, 6), '0');
        $order->save();
    }

    private function recalculateInvoice(SupplierInvoice $invoice): void
    {
        $lines = $invoice->lines()->get();
        $invoice->subtotal = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->gross_amount, 6), '0');
        $invoice->line_discount_total = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->discount_amount, 6), '0');
        $invoice->taxable_amount = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->taxable_amount, 6), '0');
        $invoice->tax_total = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->tax_amount, 6), '0');
        $invoice->total = $lines->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->net_amount, 6), '0');
        $invoice->remaining_amount = $invoice->total;
        $invoice->save();
    }

    private function calculateLine(array $input, ProductService $product, Company $company): array
    {
        $gross = bcmul((string) $input['quantity'], (string) $input['unit_cost'], 6);
        $discount = '0';
        $discountType = $input['discount_type'] ?? null;
        $discountValue = (string) ($input['discount_value'] ?? '0');
        if ($discountType === 'percent') {
            $discount = bcdiv(bcmul($gross, $discountValue, 6), '100', 6);
        } elseif ($discountType === 'amount') {
            $discount = $discountValue;
        }
        if (bccomp($discount, $gross, 6) > 0) {
            throw new RegistryConflictException('Line discount cannot exceed the gross line amount.');
        }
        $tax = ! empty($input['tax_code_id']) ? TaxCode::where('company_id', $company->id)->where('status', 'active')->whereKey($input['tax_code_id'])->first() : null;
        if (! empty($input['tax_code_id']) && ! $tax) {
            throw new RegistryConflictException('The selected Tax Code is not active in this company.', ['dependency' => 'tax_code']);
        }
        $taxable = bcsub($gross, $discount, 6);
        $rate = (string) ($tax?->rate ?? 0);
        $basis = $tax?->basis ?? 'exclusive';
        $taxAmount = $rate === '0' ? '0' : ($basis === 'inclusive' ? bcsub($taxable, bcdiv($taxable, bcadd('1', bcdiv($rate, '100', 6), 6), 6), 6) : bcdiv(bcmul($taxable, $rate, 6), '100', 6));
        $net = $basis === 'inclusive' ? $taxable : bcadd($taxable, $taxAmount, 6);

        return ['tax' => $tax, 'rate' => $rate, 'basis' => $basis, 'amounts' => ['gross_amount' => $gross, 'discount_amount' => $discount, 'taxable_amount' => $taxable, 'tax_amount' => $taxAmount, 'net_amount' => $net]];
    }

    private function invoiceExceptions(SupplierInvoice $invoice, Company $company): array
    {
        $exceptions = [];
        foreach ($invoice->lines as $line) {
            $poLine = $line->purchase_order_line_id ? PurchaseOrderLine::where('company_id', $company->id)->find($line->purchase_order_line_id) : null;
            if (! $poLine) {
                continue;
            }
            $remaining = bcsub((string) $poLine->quantity, (string) $poLine->invoiced_quantity, 6);
            if (bccomp((string) $line->quantity, $remaining, 6) > 0) {
                $exceptions[] = ['line_id' => $line->id, 'type' => 'quantity_variance', 'expected' => $remaining, 'actual' => $line->quantity, 'message' => 'Invoice quantity exceeds the remaining Purchase Order quantity.'];
            }
            if ($line->stock_managed_snapshot && ! $line->goods_receipt_line_id) {
                $exceptions[] = ['line_id' => $line->id, 'type' => 'missing_receipt', 'message' => 'A stock-managed supplier invoice requires an accepted Goods Receipt.'];
            }
            $priceVariance = bcsub(bcmul((string) $line->quantity, (string) $line->unit_cost, 6), bcmul((string) $line->quantity, (string) $poLine->unit_cost, 6), 6);
            if (bccomp(ltrim($priceVariance, '-'), (string) ($invoice->match_tolerance_amount ?? 0), 6) > 0) {
                $exceptions[] = ['line_id' => $line->id, 'type' => 'price_variance', 'expected' => $poLine->unit_cost, 'actual' => $line->unit_cost, 'message' => 'Invoice unit cost differs from the Purchase Order.'];
            }
        }

        return $exceptions;
    }

    private function matchVariance(SupplierInvoice $invoice): string
    {
        return $invoice->lines->reduce(function (string $sum, SupplierInvoiceLine $line) {
            if (! $line->purchase_order_line_id) {
                return $sum;
            }
            $poLine = PurchaseOrderLine::find($line->purchase_order_line_id);
            if (! $poLine) {
                return $sum;
            }
            $variance = bcsub(bcmul((string) $line->quantity, (string) $line->unit_cost, 6), bcmul((string) $line->quantity, (string) $poLine->unit_cost, 6), 6);

            return bcadd($sum, ltrim($variance, '-'), 6);
        }, '0');
    }

    private function payableAccounts(Company $company, SupplierInvoice $invoice): array
    {
        $active = fn (string $classification) => AccountTitle::where('company_id', $company->id)->where('classification', $classification)->where('status', 'active')->where('posting_eligible', true);
        $payable = (clone $active('liability'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%payable%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%payable%'])->orWhereRaw('LOWER(code) LIKE ?', ['%payable%']))->first();
        $inventory = (clone $active('asset'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%inventory%'])->orWhereRaw('LOWER(name) LIKE ?', ['%stock%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%inventory%']))->first();
        $expense = (clone $active('expense'))->first();
        $tax = (clone $active('asset'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%tax%'])->orWhereRaw('LOWER(name) LIKE ?', ['%vat%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%tax%']))->first();
        $needsInventory = $invoice->lines->contains(fn ($line) => $line->stock_managed_snapshot);
        if (! $payable || ($needsInventory && ! $inventory) || (! $needsInventory && ! $expense) || (bccomp((string) $invoice->tax_total, '0', 6) > 0 && ! $tax)) {
            throw new RegistryConflictException('Supplier Invoice posting is blocked until active posting Account Titles are configured for Accounts Payable, Inventory or Expense, and applicable Recoverable Tax.', ['dependency' => 'account_titles']);
        }

        return compact('payable', 'inventory', 'expense', 'tax');
    }

    private function accountingLine(string $transactionId, string $accountId, string $debit, string $credit, string $currency, string $description): void
    {
        DB::table('accounting_transaction_lines')->insert(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $transactionId, 'account_title_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'currency_code' => $currency, 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function supplier(Company $company, string $id): BusinessPartner
    {
        $supplier = BusinessPartner::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'supplier')->where('status', 'active'))->first();
        if (! $supplier) {
            throw new RegistryConflictException('An active same-company Supplier is required.', ['dependency' => 'supplier']);
        }

        return $supplier;
    }

    private function product(Company $company, string $id): ProductService
    {
        $product = ProductService::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->where('purchasable', true)->with('baseUnit')->first();
        if (! $product) {
            throw new RegistryConflictException('An active same-company purchasable Product or Service is required.', ['dependency' => 'product_service']);
        }

        return $product;
    }

    private function currency(Company $company, string $id): ReferenceCurrency
    {
        $currency = ReferenceCurrency::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->first();
        if (! $currency) {
            throw new RegistryConflictException('An active same-company Currency is required.', ['dependency' => 'currency']);
        }

        return $currency;
    }

    private function term(Company $company, string $id): PaymentTerm
    {
        $term = PaymentTerm::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->first();
        if (! $term) {
            throw new RegistryConflictException('An active same-company Payment Term is required.', ['dependency' => 'payment_term']);
        }

        return $term;
    }

    private function dueDate(string $date, ?PaymentTerm $term, ?string $provided, Company $company): ?string
    {
        if (! $term) {
            return $provided;
        } if ($term->term_type === 'due_date') {
            if (! $provided || Carbon::parse($provided)->lt(Carbon::parse($date))) {
                throw new RegistryConflictException('A due date on or after the Invoice date is required.');
            }

            return $provided;
        } $due = Carbon::parse($date, $company->timezone ?: config('app.timezone'));
        if (($term->due_days ?? 0) > 0) {
            $due->addDays($term->due_days);
        } if ($term->end_of_month) {
            $due->endOfMonth();
        }

        return $due->toDateString();
    }

    private function dueStatus($date, Company $company): string
    {
        if (! $date) {
            return 'no_due_date';
        } $today = Carbon::today($company->timezone ?: config('app.timezone'));
        $due = Carbon::parse($date);

        return $due->lt($today) ? 'overdue' : ($due->equalTo($today) ? 'due_today' : 'not_yet_due');
    }

    private function validateReceiptQuantities(PurchaseOrderLine $line, array $input): void
    {
        $quantity = (string) $input['quantity'];
        $rejected = (string) ($input['rejected_quantity'] ?? 0);
        $damaged = (string) ($input['damaged_quantity'] ?? 0);
        if (bccomp(bcadd($rejected, $damaged, 6), $quantity, 6) > 0) {
            throw new RegistryConflictException('Rejected and damaged quantities cannot exceed the received quantity.');
        } $remaining = bcsub((string) $line->quantity, (string) $line->received_quantity, 6);
        if (bccomp($quantity, $remaining, 6) > 0) {
            throw new RegistryConflictException('Goods Receipt quantity exceeds the remaining Purchase Order quantity.', ['dependency' => 'over_receipt']);
        }
    }

    private function acceptedQuantity(array $input): string
    {
        return (string) ($input['accepted_quantity'] ?? bcsub(bcsub((string) $input['quantity'], (string) ($input['rejected_quantity'] ?? 0), 6), (string) ($input['damaged_quantity'] ?? 0), 6));
    }

    private function shortQuantity(PurchaseOrderLine $line, string $quantity): string
    {
        $remaining = bcsub((string) $line->quantity, (string) $line->received_quantity, 6);

        return bccomp($remaining, $quantity, 6) > 0 ? bcsub($remaining, $quantity, 6) : '0';
    }

    private function overQuantity(PurchaseOrderLine $line, string $quantity): string
    {
        $remaining = bcsub((string) $line->quantity, (string) $line->received_quantity, 6);

        return bccomp($quantity, $remaining, 6) > 0 ? bcsub($quantity, $remaining, 6) : '0';
    }

    private function setOrderStatus(PurchaseOrder $order, string $from, string $to, ?int $actor): string
    {
        if ($order->status !== $from) {
            throw new RegistryConflictException('Purchase Order cannot take this action from its current status.', ['status' => $order->status]);
        } $order->status = $to;
        $order->submitted_by ??= $actor;
        $order->submitted_at ??= now();
        if ($to === 'awaiting_approval') {
            $order->reviewed_by = $actor;
            $order->reviewed_at = now();
        } $order->version++;
        $order->save();

        return $to;
    }

    private function approveOrder(PurchaseOrder $order, ?int $actor): string
    {
        if (! in_array($order->status, ['submitted', 'awaiting_approval'], true)) {
            throw new RegistryConflictException('Only submitted Purchase Orders can be approved.');
        } if ($actor && in_array($actor, array_filter([$order->created_by, $order->submitted_by]), true)) {
            throw new RegistryConflictException('The preparer cannot approve the same Purchase Order.');
        } $order->status = 'approved';
        $order->approved_by = $actor;
        $order->approved_at = now();
        $order->version++;
        $order->save();

        return 'approved';
    }

    private function cancelOrder(PurchaseOrder $order, ?int $actor, ?string $reason): string
    {
        if (in_array($order->status, ['closed', 'cancelled'], true)) {
            throw new RegistryConflictException('This Purchase Order cannot be cancelled from its current status.');
        } if (! $reason) {
            throw new RegistryConflictException('A cancellation reason is required.');
        } $order->status = 'cancelled';
        $order->cancelled_by = $actor;
        $order->cancelled_at = now();
        $order->cancellation_reason = $reason;
        $order->version++;
        $order->save();

        return 'cancelled';
    }

    private function closeOrder(PurchaseOrder $order, ?int $actor): string
    {
        if (in_array($order->status, ['cancelled', 'closed'], true)) {
            throw new RegistryConflictException('This Purchase Order cannot be closed from its current status.');
        } if ($order->lines()->whereRaw('received_quantity + cancelled_quantity < quantity')->exists()) {
            throw new RegistryConflictException('A Purchase Order can only be closed when all remaining quantities are received or cancelled.');
        } $order->status = 'closed';
        $order->closed_by = $actor;
        $order->closed_at = now();
        $order->version++;
        $order->save();

        return 'closed';
    }

    private function refreshOrderStatus(PurchaseOrder $order): void
    {
        if (! $order) {
            return;
        } $lines = $order->lines()->get();
        $allReceived = $lines->isNotEmpty() && $lines->every(fn ($line) => bccomp(bcadd((string) $line->received_quantity, (string) $line->cancelled_quantity, 6), (string) $line->quantity, 6) >= 0);
        $anyReceived = $lines->contains(fn ($line) => bccomp((string) $line->received_quantity, '0', 6) > 0);
        $allInvoiced = $lines->isNotEmpty() && $lines->every(fn ($line) => bccomp(bcadd((string) $line->invoiced_quantity, (string) $line->cancelled_quantity, 6), (string) $line->quantity, 6) >= 0);
        $anyInvoiced = $lines->contains(fn ($line) => bccomp((string) $line->invoiced_quantity, '0', 6) > 0);
        $order->status = $allInvoiced ? 'fully_invoiced' : ($anyInvoiced ? 'partially_invoiced' : ($allReceived ? 'fully_received' : ($anyReceived ? 'partially_received' : $order->status)));
        $order->received_amount = $lines->reduce(fn (string $sum, $line) => bcadd($sum, bcmul((string) $line->received_quantity, (string) $line->unit_cost, 6), 6), '0');
        $order->invoiced_amount = $lines->reduce(fn (string $sum, $line) => bcadd($sum, bcmul((string) $line->invoiced_quantity, (string) $line->unit_cost, 6), 6), '0');
        $order->save();
    }

    private function syncIncoming(Company $company): void
    {
        $positions = [];
        PurchaseOrder::where('company_id', $company->id)->whereIn('status', ['approved', 'partially_received', 'partially_invoiced'])->with('lines')->get()->each(function ($order) use (&$positions) {
            foreach ($order->lines as $line) {
                $remaining = bcsub(bcsub((string) $line->quantity, (string) $line->received_quantity, 6), (string) $line->cancelled_quantity, 6);
                if ($line->stock_managed_snapshot && bccomp($remaining, '0', 6) > 0 && $line->warehouse_id && $line->stock_location_id) {
                    $positions[] = ['product_service_id' => $line->product_service_id, 'warehouse_id' => $line->warehouse_id, 'stock_location_id' => $line->stock_location_id, 'unit_of_measure_id' => $line->unit_of_measure_id, 'quantity' => $remaining];
                }
            }
        });
        $this->inventory->syncPurchaseIncoming($company, $positions);
    }

    private function refreshPayableStatuses(Company $company): void
    {
        $today = Carbon::today($company->timezone ?: config('app.timezone'));
        PayableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->get()->each(function ($item) use ($today) {
            $dueStatus = ! $item->due_date ? 'no_due_date' : (Carbon::parse($item->due_date)->lt($today) ? 'overdue' : (Carbon::parse($item->due_date)->equalTo($today) ? 'due_today' : 'not_yet_due'));
            $settlement = $dueStatus === 'overdue' ? 'overdue' : ($item->paid_amount > 0 ? 'partially_paid' : 'unpaid');
            if ($item->due_status !== $dueStatus || $item->settlement_status !== $settlement) {
                $item->update(['due_status' => $dueStatus, 'settlement_status' => $settlement, 'last_calculated_at' => now(), 'version' => $item->version + 1]);
            }
        });
    }

    private function assertVersion($model, array $input): void
    {
        if (array_key_exists('version', $input) && $input['version'] !== null && (int) $input['version'] !== (int) $model->version) {
            throw new RegistryConflictException('This record was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
    }

    private function pagination($page): array
    {
        return ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]];
    }
}
