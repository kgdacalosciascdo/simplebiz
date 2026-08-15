<?php

namespace App\Services;

use App\Events\SalesLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\BillingStatement;
use App\Models\BusinessPartner;
use App\Models\BusinessTransaction;
use App\Models\Company;
use App\Models\PaymentTerm;
use App\Models\ProductService;
use App\Models\ReceivableOpenItem;
use App\Models\ReferenceCurrency;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalesAdjustment;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\SaleStatusHistory;
use App\Models\TaxCode;
use App\Support\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SalesService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers, private readonly InventoryService $inventory, private readonly InventoryCompletionService $inventoryCompletion) {}

    public function list(Company $company, Request $request)
    {
        $query = Sale::where('company_id', $company->id)->with(['customer', 'currency'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('payment_basis'), fn ($q) => $q->where('payment_basis', $request->string('payment_basis')))->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('sale_number', 'ilike', '%'.$request->string('q').'%')->orWhere('customer_reference', 'ilike', '%'.$request->string('q').'%')->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'ilike', '%'.$request->string('q').'%'))));
        $page = $query->latest('sale_date')->latest('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]];
    }

    public function lookups(Company $company): array
    {
        return [
            'customers' => BusinessPartner::where('company_id', $company->id)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'customer')->where('status', 'active'))->orderBy('display_name')->get(['id', 'code', 'display_name']),
            'items' => ProductService::where('company_id', $company->id)->where('status', 'active')->where('sellable', true)->with('baseUnit')->orderBy('name')->get(['id', 'code', 'name', 'record_type', 'base_unit_id', 'stock_managed', 'non_stock', 'standard_selling_price', 'tax_reference']),
            'currencies' => ReferenceCurrency::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'symbol', 'decimal_precision']),
            'payment_terms' => PaymentTerm::where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name', 'term_type', 'due_days', 'end_of_month']),
            'tax_codes' => TaxCode::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'tax_type', 'rate', 'basis']),
        ];
    }

    public function summary(Company $company): array
    {
        $sales = Sale::where('company_id', $company->id);
        $receivables = ReceivableOpenItem::where('company_id', $company->id);
        $returns = SalesReturn::where('company_id', $company->id);
        $adjustments = SalesAdjustment::where('company_id', $company->id);

        return ['sales' => ['total' => (clone $sales)->count(), 'draft' => (clone $sales)->where('status', 'draft')->count(), 'awaiting_review' => (clone $sales)->where('status', 'for_approval')->count(), 'approved' => (clone $sales)->where('status', 'approved')->count(), 'posted' => (clone $sales)->where('status', 'posted')->count(), 'blocked' => (clone $sales)->where('status', 'failed')->count()], 'receivables' => ['open_items' => (clone $receivables)->where('remaining_amount', '>', 0)->count(), 'overdue' => (clone $receivables)->where('remaining_amount', '>', 0)->where('due_status', 'overdue')->count(), 'due_today' => (clone $receivables)->where('remaining_amount', '>', 0)->where('due_status', 'due_today')->count()], 'corrections' => ['returns_pending' => (clone $returns)->whereIn('status', ['draft', 'for_approval', 'approved'])->count(), 'returns_posted' => (clone $returns)->where('status', 'posted')->count(), 'adjustments_pending' => (clone $adjustments)->whereIn('status', ['draft', 'for_approval', 'approved'])->count(), 'adjustments_posted' => (clone $adjustments)->where('status', 'posted')->count()], 'recent_activity' => SaleStatusHistory::where('company_id', $company->id)->latest()->limit(10)->get(['id', 'sale_id', 'from_status', 'to_status', 'reason', 'created_at'])];
    }

    public function dashboard(Company $company, Request $request): array
    {
        $today = Carbon::today($company->timezone ?: config('app.timezone'));
        $from = Carbon::parse($request->input('from', $today->copy()->startOfMonth()->toDateString()));
        $to = Carbon::parse($request->input('to', $today->toDateString()));
        $sales = Sale::where('company_id', $company->id)
            ->whereIn('status', ['posted', 'reversed'])
            ->whereDate('sale_date', '>=', $from->toDateString())
            ->whereDate('sale_date', '<=', $to->toDateString())
            ->with('currency')
            ->get();
        $returns = SalesReturn::where('company_id', $company->id)->where('status', 'posted')->whereDate('return_date', '>=', $from->toDateString())->whereDate('return_date', '<=', $to->toDateString())->with('currency')->get();
        $adjustments = SalesAdjustment::where('company_id', $company->id)->where('status', 'posted')->whereDate('adjustment_date', '>=', $from->toDateString())->whereDate('adjustment_date', '<=', $to->toDateString())->with('currency')->get();
        $receivables = ReceivableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->with('currency')->get();
        $currencyIds = $sales->pluck('currency_id')->merge($receivables->pluck('currency_id'))->merge($returns->pluck('currency_id'))->merge($adjustments->pluck('currency_id'))->filter()->unique()->values();
        $metrics = $currencyIds->map(function ($currencyId) use ($sales, $returns, $adjustments, $receivables, $from, $to) {
            $saleRows = $sales->where('currency_id', $currencyId);
            $returnRows = $returns->where('currency_id', $currencyId);
            $adjustmentRows = $adjustments->where('currency_id', $currencyId);
            $openRows = $receivables->where('currency_id', $currencyId);
            $salesAmount = (string) $saleRows->sum('total');
            $returnsAmount = (string) $returnRows->sum('total_amount');
            $debitAdjustments = (string) $adjustmentRows->where('adjustment_type', 'debit')->sum('total_amount');
            $creditAdjustments = (string) $adjustmentRows->where('adjustment_type', 'credit')->sum('total_amount');
            $netSales = bcsub(bcadd($salesAmount, $debitAdjustments, 6), bcadd($returnsAmount, $creditAdjustments, 6), 6);
            $openAmount = (string) $openRows->sum('remaining_amount');
            $overdueAmount = (string) $openRows->where('due_status', 'overdue')->sum('remaining_amount');
            $dueSoonAmount = (string) $openRows->filter(fn ($item) => $item->due_date && $item->due_date->between($from->copy()->max(Carbon::today()), Carbon::today()->addDays(7)))->sum('remaining_amount');
            $currency = $saleRows->first()?->currency ?: $openRows->first()?->currency ?: $returnRows->first()?->currency ?: $adjustmentRows->first()?->currency;

            return [
                'currency_id' => $currencyId,
                'currency' => $currency ? ['id' => $currency->id, 'code' => $currency->code, 'symbol' => $currency->symbol] : null,
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'sales_count' => $saleRows->count(),
                'sales_amount' => $salesAmount,
                'returns_amount' => $returnsAmount,
                'adjustments_debit' => $debitAdjustments,
                'adjustments_credit' => $creditAdjustments,
                'net_sales' => $netSales,
                'open_receivable_count' => $openRows->count(),
                'open_receivable_amount' => $openAmount,
                'overdue_count' => $openRows->where('due_status', 'overdue')->count(),
                'overdue_amount' => $overdueAmount,
                'due_soon_count' => $openRows->filter(fn ($item) => $item->due_date && $item->due_date->between($from->copy()->max(Carbon::today()), Carbon::today()->addDays(7)))->count(),
                'due_soon_amount' => $dueSoonAmount,
                'source_owner' => 'MDS-200',
            ];
        })->values()->all();

        return ['as_of_at' => now()->toISOString(), 'freshness_state' => 'current', 'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'currency_context' => 'Sales and receivable amounts are returned in separate currency buckets.', 'metrics' => $metrics, 'recent_activity' => SaleStatusHistory::where('company_id', $company->id)->with('sale')->latest()->limit(10)->get()->map(fn ($history) => ['id' => $history->id, 'sale_id' => $history->sale_id, 'sale_number' => $history->sale?->sale_number, 'from_status' => $history->from_status, 'to_status' => $history->to_status, 'reason' => $history->reason, 'occurred_at' => $history->created_at?->toISOString()])->values()->all()];
    }

    public function attention(Company $company): array
    {
        $items = collect();
        $sales = Sale::where('company_id', $company->id)->whereIn('status', ['failed', 'for_approval'])->with(['customer', 'currency'])->latest('updated_at')->get();
        foreach ($sales as $sale) {
            $items->push(['id' => 'sale-'.$sale->id, 'kind' => $sale->status === 'failed' ? 'posting_failed' : 'approval_required', 'severity' => $sale->status === 'failed' ? 'high' : 'medium', 'title' => $sale->status === 'failed' ? 'Sale posting failed' : 'Sale awaiting approval', 'detail' => $sale->sale_number.' · '.($sale->customer?->display_name ?: 'Unidentified customer'), 'amount' => (string) $sale->total, 'currency' => $sale->currency?->code, 'source_owner' => 'MDS-200', 'source_id' => $sale->id, 'route' => '/sales/'.$sale->id]);
        }
        $receivables = ReceivableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->whereIn('due_status', ['overdue', 'due_today'])->with(['customer', 'currency', 'sourceSale'])->orderBy('due_date')->get();
        foreach ($receivables as $item) {
            $items->push(['id' => 'receivable-'.$item->id, 'kind' => $item->due_status === 'overdue' ? 'overdue_receivable' : 'due_today_receivable', 'severity' => $item->due_status === 'overdue' ? 'high' : 'medium', 'title' => $item->due_status === 'overdue' ? 'Overdue customer balance' : 'Receivable due today', 'detail' => ($item->sourceSale?->sale_number ?: $item->source_document_number).' · '.($item->customer?->display_name ?: 'Customer'), 'amount' => (string) $item->remaining_amount, 'currency' => $item->currency?->code, 'source_owner' => 'MDS-200', 'source_id' => $item->id, 'route' => '/sales/'.$item->source_sale_id]);
        }
        foreach (SalesReturn::where('company_id', $company->id)->whereIn('status', ['draft', 'for_approval', 'approved'])->with('currency')->latest('updated_at')->get() as $return) {
            $items->push(['id' => 'return-'.$return->id, 'kind' => 'return_approval', 'severity' => 'medium', 'title' => 'Sales return requires action', 'detail' => $return->return_number, 'amount' => (string) $return->total_amount, 'currency' => $return->currency?->code, 'source_owner' => 'MDS-200', 'source_id' => $return->id, 'route' => '/sales/'.$return->sale_id]);
        }
        foreach (SalesAdjustment::where('company_id', $company->id)->whereIn('status', ['draft', 'for_approval', 'approved'])->with('currency')->latest('updated_at')->get() as $adjustment) {
            $items->push(['id' => 'adjustment-'.$adjustment->id, 'kind' => 'adjustment_approval', 'severity' => 'medium', 'title' => 'Sales adjustment requires action', 'detail' => $adjustment->adjustment_number, 'amount' => (string) $adjustment->total_amount, 'currency' => $adjustment->currency?->code, 'source_owner' => 'MDS-200', 'source_id' => $adjustment->id, 'route' => '/sales/'.$adjustment->sale_id]);
        }

        return ['as_of_at' => now()->toISOString(), 'freshness_state' => 'current', 'source_owner' => 'MDS-200', 'items' => $items->take(50)->values()->all(), 'total' => $items->count()];
    }

    public function createDraft(array $input, Company $company, Request $request): Sale
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $sale = new Sale;
            $sale->id = (string) Str::uuid();
            $sale->company_id = $company->id;
            $sale->sale_number = $this->numbers->next($company->id, 'sale');
            $sale->sale_type = $input['sale_type'];
            $sale->payment_basis = $input['payment_basis'] ?? ($input['sale_type'] === 'cash_sale' ? 'cash' : 'credit');
            $sale->sale_date = $input['sale_date'];
            $sale->created_by = $request->user()?->id;
            $sale->prepared_at = now();
            $sale->status = 'draft';
            $sale->correlation_id = $request->attributes->get('correlation_id');
            $sale->idempotency_identity = $request->header('Idempotency-Key');
            $this->applyReferencesAndCalculate($sale, $input, $company, $request);
            $sale->save();
            $this->writeLines($sale, $input['lines'], $company, $request);
            $this->recalculateStoredSale($sale, $input, $company, $request);
            $this->inventoryCompletion->syncSaleReservations($sale, $company, $request);
            $this->audit($request, 'sales.draft.created', $sale, [], $sale->toArray(), 'A Sales draft was created.');
            SalesLifecycleEvent::dispatch('sales.draft.created', $company->id, 'sale', $sale->id);

            return $sale->fresh(['lines', 'customer', 'currency', 'paymentTerm']);
        });
    }

    public function updateDraft(Sale $sale, array $input, Company $company, Request $request): Sale
    {
        if ($sale->status !== 'draft') {
            throw new RegistryConflictException('Only Draft Sales can be edited.', ['status' => $sale->status]);
        }
        $this->assertVersion($sale, $input);

        return DB::transaction(function () use ($sale, $input, $company, $request) {
            $before = $sale->toArray();
            $sale->sale_date = $input['sale_date'] ?? $sale->sale_date;
            $this->applyReferencesAndCalculate($sale, $input + ['lines' => $sale->lines->toArray()], $company, $request);
            $sale->version++;
            $sale->save();
            if (array_key_exists('lines', $input)) {
                $sale->lines()->delete();
                $this->writeLines($sale, $input['lines'], $company, $request);
            }
            $this->recalculateStoredSale($sale, $input + ['lines' => $sale->lines->toArray()], $company, $request);
            $this->inventoryCompletion->syncSaleReservations($sale, $company, $request);
            $this->audit($request, 'sales.draft.updated', $sale, $before, $sale->toArray(), 'A Sales draft was updated.');

            return $sale->fresh(['lines', 'customer', 'currency', 'paymentTerm']);
        });
    }

    public function transition(Sale $sale, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): Sale
    {
        try {
            return DB::transaction(function () use ($sale, $action, $company, $request, $reason, $version) {
                $sale = Sale::where('company_id', $company->id)->whereKey($sale->id)->with('lines')->lockForUpdate()->firstOrFail();
                if ($version !== null && $version !== (int) $sale->version) {
                    throw new RegistryConflictException('This Sale was changed by another user. Refresh and try again.', ['version_conflict' => true]);
                }
                $before = $sale->toArray();
                $actor = $request->user()?->id;
                $from = $sale->status;
                $to = match ($action) {
                    'submit' => $this->transitionTo($sale, 'draft', 'for_approval'),
                    'review' => $this->review($sale, $actor),
                    'return' => $this->returnForCorrection($sale, $actor, $reason),
                    'approve' => $this->approve($sale, $actor),
                    'post' => $this->post($sale, $company, $request),
                    'cancel' => $this->cancel($sale, $actor, $reason),
                    default => throw new RegistryConflictException('Unsupported Sales action.'),
                };
                if ($action === 'cancel') {
                    $this->inventoryCompletion->releaseSaleReservations($sale, $company, $request);
                }
                if ($to !== null && $to !== $from) {
                    SaleStatusHistory::create(['id' => (string) Str::uuid(), 'sale_id' => $sale->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $actor, 'version' => $sale->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
                }
                $this->audit($request, 'sales.'.$action, $sale, $before, $sale->toArray(), $reason ?: 'Sales lifecycle action completed.');
                SalesLifecycleEvent::dispatch('sales.'.$action, $company->id, 'sale', $sale->id);

                return $sale->fresh(['lines', 'customer', 'currency', 'paymentTerm', 'receivable', 'statusHistory']);
            });
        } catch (RegistryConflictException $exception) {
            if ($action === 'post' && ! ($exception->errors['version_conflict'] ?? false) && isset($exception->errors['dependency'])) {
                $failed = Sale::where('company_id', $company->id)->whereKey($sale->id)->first();
                if ($failed) {
                    $from = $failed->status;
                    $failed->status = 'failed';
                    $failed->blocked_code = $exception->errors['dependency'];
                    $failed->blocked_reason = $exception->getMessage();
                    $failed->version++;
                    $failed->save();
                    SaleStatusHistory::create(['id' => (string) Str::uuid(), 'sale_id' => $failed->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => 'failed', 'reason' => $exception->getMessage(), 'actor_id' => $request->user()?->id, 'version' => $failed->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
                    $this->audit($request, 'sales.post.failed', $failed, ['status' => $from], $failed->toArray(), $exception->getMessage());
                }
            }
            throw $exception;
        }
    }

    public function postPaidNowCommercial(Sale $sale, Company $company, Request $request): Sale
    {
        return DB::transaction(function () use ($sale, $company, $request) {
            $locked = Sale::where('company_id', $company->id)
                ->whereKey($sale->id)
                ->with('lines')
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'posted') {
                return $locked->fresh(['lines', 'customer', 'currency', 'paymentTerm', 'receivable', 'statusHistory', 'inventoryMovements']);
            }
            if ($locked->status !== 'approved' || $locked->payment_basis !== 'cash') {
                throw new RegistryConflictException('Only an Approved cash Sale can be commercially posted for paid-now completion.', ['status' => $locked->status]);
            }

            $this->post($locked, $company, $request, true);

            return $locked->fresh(['lines', 'customer', 'currency', 'paymentTerm', 'receivable', 'statusHistory', 'inventoryMovements']);
        });
    }

    public function receivables(Company $company, Request $request)
    {
        $query = ReceivableOpenItem::where('company_id', $company->id)->with(['customer', 'sourceSale', 'currency'])->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')))->when($request->filled('status'), fn ($q) => $q->where('settlement_status', $request->string('status')))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('source_document_number', 'ilike', '%'.$request->string('q').'%')->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'ilike', '%'.$request->string('q').'%'))));
        $page = $query->orderBy('due_date')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]];
    }

    public function aging(Company $company, Request $request): array
    {
        $items = ReceivableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->get();
        $today = Carbon::today($company->timezone ?: config('app.timezone'));
        $buckets = ['current' => '0', '1_30' => '0', '31_60' => '0', '61_90' => '0', 'over_90' => '0'];
        foreach ($items as $item) {
            if (! $item->due_date || $item->due_date->gte($today)) {
                $bucket = 'current';
            } else {
                $days = $item->due_date->diffInDays($today);
                $bucket = $days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90'));
            }
            $buckets[$bucket] = bcadd($buckets[$bucket], (string) $item->remaining_amount, 6);
        }

        return $buckets;
    }

    /**
     * Read-only source contracts consumed by MDS-900.
     *
     * Sales & Receivables remains the owner of the status, amount, return,
     * discount, and open-item meaning. Reports only shapes those governed
     * records for a report definition.
     */
    public function report(string $report, Company $company, Request $request): array
    {
        $from = $request->input('from');
        $to = $request->input('to');
        $asOf = $request->input('as_of') ?: Carbon::today($company->timezone ?: config('app.timezone'))->toDateString();

        return match ($report) {
            'sales_register' => $this->salesRegister($company, $from, $to),
            'sales_by_product' => $this->salesByProduct($company, $from, $to),
            'sales_returns_adjustments' => $this->salesReturnsAdjustments($company, $from, $to),
            'receivables_aging' => $this->receivablesAgingReport($company, $asOf),
            default => throw new RegistryConflictException('The requested Sales source report is not supported.'),
        };
    }

    private function salesRegister(Company $company, ?string $from, ?string $to): array
    {
        $sales = Sale::where('company_id', $company->id)
            ->whereIn('status', ['posted', 'reversed'])
            ->when($from, fn ($query) => $query->whereDate('sale_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('sale_date', '<=', $to))
            ->with(['customer', 'currency'])
            ->latest('sale_date')
            ->get();
        $saleIds = $sales->pluck('id');
        $returns = SalesReturn::where('company_id', $company->id)->whereIn('sale_id', $saleIds)->whereIn('status', ['posted', 'reversed'])->get()->groupBy('sale_id');
        $adjustments = SalesAdjustment::where('company_id', $company->id)->whereIn('sale_id', $saleIds)->whereIn('status', ['posted', 'reversed'])->get()->groupBy('sale_id');

        return [
            'rows' => $sales->map(function (Sale $sale) use ($returns, $adjustments) {
                $saleReturns = $returns->get($sale->id, collect())->where('status', 'posted');
                $saleAdjustments = $adjustments->get($sale->id, collect())->where('status', 'posted');
                $returnTotal = (string) $saleReturns->sum('total_amount');
                $debitAdjustments = (string) $saleAdjustments->where('adjustment_type', 'debit')->sum('total_amount');
                $creditAdjustments = (string) $saleAdjustments->where('adjustment_type', 'credit')->sum('total_amount');
                $netAmount = bcadd(bcsub(bcadd((string) $sale->total, $debitAdjustments, 6), $returnTotal, 6), bcmul($creditAdjustments, '-1', 6), 6);

                return [
                    'id' => $sale->id,
                    'sale_number' => $sale->sale_number,
                    'date' => $sale->sale_date?->toDateString(),
                    'customer' => $sale->customer?->display_name,
                    'status' => $sale->status,
                    'currency' => $sale->currency?->code,
                    'gross_sales' => (string) $sale->subtotal,
                    'discounts' => (string) bcadd((string) $sale->line_discount_total, (string) $sale->document_discount_total, 6),
                    'tax' => (string) $sale->tax_total,
                    'amount' => (string) $sale->total,
                    'returns' => $returnTotal,
                    'debit_adjustments' => $debitAdjustments,
                    'credit_adjustments' => $creditAdjustments,
                    'net_amount' => $netAmount,
                    'receivable_amount' => (string) $sale->receivable_amount,
                    'return_status' => $sale->return_status,
                ];
            })->values()->all(),
            'source_as_of_at' => now(),
            'freshness_state' => 'current',
            'currency_context' => 'Sales amounts remain separated by source currency.',
        ];
    }

    private function salesByProduct(Company $company, ?string $from, ?string $to): array
    {
        $lines = SaleLine::where('company_id', $company->id)
            ->whereHas('sale', function ($query) use ($company, $from, $to) {
                $query->where('company_id', $company->id)
                    ->whereIn('status', ['posted', 'reversed'])
                    ->when($from, fn ($inner) => $inner->whereDate('sale_date', '>=', $from))
                    ->when($to, fn ($inner) => $inner->whereDate('sale_date', '<=', $to));
            })
            ->with(['sale.currency', 'productService'])
            ->get();
        $returnLines = SalesReturnLine::where('company_id', $company->id)->whereHas('salesReturn', function ($query) use ($company, $from, $to) {
            $query->where('company_id', $company->id)->where('status', 'posted')->when($from, fn ($inner) => $inner->whereDate('return_date', '>=', $from))->when($to, fn ($inner) => $inner->whereDate('return_date', '<=', $to));
        })->with(['salesReturn.currency', 'productService'])->get();

        $rows = [];
        foreach ($lines as $line) {
            $key = $line->product_service_id.'|'.($line->sale?->currency?->code ?: 'UNKNOWN');
            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'id' => $line->product_service_id,
                    'product' => $line->productService?->name ?: $line->description_snapshot,
                    'product_code' => $line->productService?->code ?: $line->item_code_snapshot,
                    'currency' => $line->sale?->currency?->code,
                    'quantity' => '0',
                    'gross_sales' => '0',
                    'discounts' => '0',
                    'net_sales' => '0',
                    'returns' => '0',
                    'margin' => null,
                    'margin_state' => 'unavailable_without_governed_cost',
                ];
            }
            $rows[$key]['quantity'] = bcadd($rows[$key]['quantity'], (string) $line->quantity, 6);
            $rows[$key]['gross_sales'] = bcadd($rows[$key]['gross_sales'], (string) $line->gross_amount, 6);
            $rows[$key]['discounts'] = bcadd($rows[$key]['discounts'], (string) $line->discount_amount, 6);
            $rows[$key]['net_sales'] = bcadd($rows[$key]['net_sales'], (string) $line->net_amount, 6);
        }
        foreach ($returnLines as $line) {
            $key = $line->product_service_id.'|'.($line->salesReturn?->currency?->code ?: 'UNKNOWN');
            if (! isset($rows[$key])) {
                $rows[$key] = ['id' => $line->product_service_id, 'product' => $line->productService?->name ?: $line->product_name_snapshot, 'product_code' => $line->productService?->code ?: $line->product_code_snapshot, 'currency' => $line->salesReturn?->currency?->code, 'quantity' => '0', 'gross_sales' => '0', 'discounts' => '0', 'net_sales' => '0', 'returns' => '0', 'margin' => null, 'margin_state' => 'unavailable_without_governed_cost'];
            }
            $rows[$key]['returns'] = bcadd($rows[$key]['returns'], (string) $line->total_amount, 6);
        }

        return [
            'rows' => array_values($rows),
            'source_as_of_at' => now(),
            'freshness_state' => 'current',
            'currency_context' => 'Sales amounts remain separated by source currency; margin is unavailable unless a governed cost source exists.',
        ];
    }

    private function salesReturnsAdjustments(Company $company, ?string $from, ?string $to): array
    {
        $returns = SalesReturn::where('company_id', $company->id)->whereIn('status', ['posted', 'reversed'])->when($from, fn ($query) => $query->whereDate('return_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('return_date', '<=', $to))->with(['sale', 'customer', 'currency'])->get()->map(fn (SalesReturn $return) => ['id' => $return->id, 'source_type' => 'sales_return', 'document_number' => $return->return_number, 'sale_number' => $return->sale?->sale_number, 'date' => $return->return_date?->toDateString(), 'customer' => $return->customer?->display_name, 'currency' => $return->currency?->code, 'adjustment_type' => 'return', 'amount' => (string) $return->total_amount, 'receivable_effect' => bcmul((string) $return->total_amount, '-1', 6), 'status' => $return->status]);
        $adjustments = SalesAdjustment::where('company_id', $company->id)->whereIn('status', ['posted', 'reversed'])->when($from, fn ($query) => $query->whereDate('adjustment_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('adjustment_date', '<=', $to))->with(['sale', 'customer', 'currency'])->get()->map(fn (SalesAdjustment $adjustment) => ['id' => $adjustment->id, 'source_type' => 'sales_adjustment', 'document_number' => $adjustment->adjustment_number, 'sale_number' => $adjustment->sale?->sale_number, 'date' => $adjustment->adjustment_date?->toDateString(), 'customer' => $adjustment->customer?->display_name, 'currency' => $adjustment->currency?->code, 'adjustment_type' => $adjustment->adjustment_type, 'amount' => (string) $adjustment->total_amount, 'receivable_effect' => $adjustment->adjustment_type === 'debit' ? (string) $adjustment->total_amount : bcmul((string) $adjustment->total_amount, '-1', 6), 'status' => $adjustment->status]);

        return ['rows' => $returns->concat($adjustments)->sortByDesc('date')->values()->all(), 'source_as_of_at' => now(), 'freshness_state' => 'current', 'currency_context' => 'Sales correction amounts remain separated by source currency and are linked to their posted Sale.'];
    }

    private function receivablesAgingReport(Company $company, string $asOf): array
    {
        $items = ReceivableOpenItem::where('company_id', $company->id)
            ->where('remaining_amount', '>', 0)
            ->with(['customer', 'currency', 'sourceSale'])
            ->orderBy('due_date')
            ->get();

        $rows = $items->map(function (ReceivableOpenItem $item) use ($asOf) {
            $bucket = 'current';
            $daysPastDue = 0;
            if ($item->due_date && $item->due_date->lt(Carbon::parse($asOf))) {
                $daysPastDue = $item->due_date->diffInDays(Carbon::parse($asOf));
                $bucket = $daysPastDue <= 30 ? '1_30' : ($daysPastDue <= 60 ? '31_60' : ($daysPastDue <= 90 ? '61_90' : 'over_90'));
            }

            return [
                'id' => $item->id,
                'source_document_number' => $item->source_document_number,
                'customer' => $item->customer?->display_name,
                'currency' => $item->currency?->code,
                'due_date' => $item->due_date?->toDateString(),
                'open_item' => (string) $item->remaining_amount,
                'aging_bucket' => $bucket,
                'days_past_due' => $daysPastDue,
                'status' => $item->settlement_status,
                'due_status' => $item->due_status,
                'source_sale_id' => $item->source_sale_id,
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'source_as_of_at' => now(),
            'freshness_state' => 'current',
            'currency_context' => 'Receivable balances remain separated by source currency.',
            'as_of_basis' => 'Current governed open-item state evaluated against the requested as-of date.',
        ];
    }

    public function billingStatements(Company $company, Request $request)
    {
        $page = BillingStatement::where('company_id', $company->id)->with(['customer', 'currency'])->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')))->latest('statement_date')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]];
    }

    public function createBillingStatement(array $input, Company $company, Request $request): BillingStatement
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $customer = $this->customer($input['customer_id'], $company, false);
            $currency = $this->currency($input['currency_id'] ?? $company->default_currency_id, $company);
            $to = $input['period_to'] ?? $input['statement_date'];
            $from = $input['period_from'] ?? null;
            $allItems = ReceivableOpenItem::where('company_id', $company->id)->where('customer_id', $customer->id)->where('currency_id', $currency->id)->whereHas('sourceSale', fn ($sale) => $sale->whereIn('status', ['posted', 'reversed'])->whereDate('sale_date', '<=', $to))->with('sourceSale')->lockForUpdate()->get();
            $items = $allItems->filter(fn (ReceivableOpenItem $item) => (! $from || ! $item->sourceSale?->sale_date || $item->sourceSale->sale_date->gte(Carbon::parse($from))) && $item->sourceSale?->sale_date?->lte(Carbon::parse($to)))->values();
            $openingBalance = $from ? $allItems->filter(fn (ReceivableOpenItem $item) => $item->sourceSale?->sale_date && $item->sourceSale->sale_date->lt(Carbon::parse($from)))->sum(fn ($item) => (float) $item->remaining_amount) : 0;
            $periodSales = Sale::where('company_id', $company->id)->where('customer_id', $customer->id)->where('currency_id', $currency->id)->whereIn('status', ['posted', 'reversed'])->when($from, fn ($query) => $query->whereDate('sale_date', '>=', $from))->whereDate('sale_date', '<=', $to)->get();
            $periodReturns = SalesReturn::where('company_id', $company->id)->where('customer_id', $customer->id)->where('currency_id', $currency->id)->where('status', 'posted')->when($from, fn ($query) => $query->whereDate('return_date', '>=', $from))->whereDate('return_date', '<=', $to)->get();
            $periodAdjustments = SalesAdjustment::where('company_id', $company->id)->where('customer_id', $customer->id)->where('currency_id', $currency->id)->where('status', 'posted')->when($from, fn ($query) => $query->whereDate('adjustment_date', '>=', $from))->whereDate('adjustment_date', '<=', $to)->get();
            $periodCharges = bcadd((string) $periodSales->sum('total'), (string) $periodAdjustments->where('adjustment_type', 'debit')->sum('total_amount'), 6);
            $periodCredits = bcadd((string) $periodReturns->sum('total_amount'), (string) $periodAdjustments->where('adjustment_type', 'credit')->sum('total_amount'), 6);
            $sourceSnapshot = [
                'basis' => 'Current governed Sales, Sales Return, Sales Adjustment, and Receivable Open Item state captured at generation.',
                'sales' => $periodSales->pluck('id')->values()->all(),
                'sale_snapshots' => $periodSales->map(fn (Sale $sale) => ['id' => $sale->id, 'sale_number' => $sale->sale_number, 'sale_date' => $sale->sale_date?->toDateString(), 'status' => $sale->status, 'total' => (string) $sale->total, 'paid_amount' => (string) $sale->paid_amount, 'remaining_amount' => (string) $sale->remaining_amount])->values()->all(),
                'returns' => $periodReturns->pluck('id')->values()->all(),
                'return_snapshots' => $periodReturns->map(fn (SalesReturn $return) => ['id' => $return->id, 'return_number' => $return->return_number, 'return_date' => $return->return_date?->toDateString(), 'status' => $return->status, 'total_amount' => (string) $return->total_amount])->values()->all(),
                'adjustments' => $periodAdjustments->pluck('id')->values()->all(),
                'adjustment_snapshots' => $periodAdjustments->map(fn (SalesAdjustment $adjustment) => ['id' => $adjustment->id, 'adjustment_number' => $adjustment->adjustment_number, 'adjustment_date' => $adjustment->adjustment_date?->toDateString(), 'adjustment_type' => $adjustment->adjustment_type, 'status' => $adjustment->status, 'total_amount' => (string) $adjustment->total_amount])->values()->all(),
                'open_items' => $items->pluck('id')->values()->all(),
                'open_item_snapshots' => $items->map(fn (ReceivableOpenItem $item) => ['id' => $item->id, 'source_sale_id' => $item->source_sale_id, 'source_document_number' => $item->source_document_number, 'original_amount' => (string) $item->original_amount, 'applied_amount' => (string) $item->applied_amount, 'remaining_amount' => (string) $item->remaining_amount, 'due_date' => $item->due_date?->toDateString(), 'settlement_status' => $item->settlement_status, 'due_status' => $item->due_status])->values()->all(),
                'as_of_at' => now()->toISOString(),
            ];
            $statement = BillingStatement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'statement_number' => $this->numbers->next($company->id, 'billing_statement'), 'customer_id' => $customer->id, 'branch_id' => $input['branch_id'] ?? null, 'currency_id' => $currency->id, 'statement_date' => $input['statement_date'], 'period_from' => $from, 'period_to' => $to, 'as_of_at' => now(), 'status' => 'generated', 'opening_balance' => $openingBalance, 'period_charges' => $periodCharges, 'period_credits' => $periodCredits, 'period_applications' => $items->sum(fn ($item) => (float) $item->applied_amount), 'ending_balance' => $allItems->sum(fn ($item) => (float) $item->remaining_amount), 'filters' => ['customer_id' => $customer->id, 'currency_id' => $currency->id, 'period_from' => $from, 'period_to' => $to], 'source_snapshot' => $sourceSnapshot, 'generated_by' => $request->user()?->id, 'generated_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key'), 'version' => 1]);
            foreach ($items as $item) {
                $statement->openItems()->attach($item->id, ['source_sale_id' => $item->source_sale_id, 'included_amount' => $item->remaining_amount]);
            }
            $this->audit($request, 'sales.billing_statement.generated', $statement, [], $statement->toArray(), 'A billing statement was generated from existing receivable open items.');
            SalesLifecycleEvent::dispatch('sales.billing_statement.generated', $company->id, 'billing_statement', $statement->id);

            return $statement->fresh(['customer', 'currency', 'openItems.sourceSale']);
        });
    }

    private function applyReferencesAndCalculate(Sale $sale, array $input, Company $company, Request $request): void
    {
        $sale->sale_type = $input['sale_type'] ?? $sale->sale_type;
        $sale->payment_basis = $input['payment_basis'] ?? ($sale->sale_type === 'cash_sale' ? 'cash' : 'credit');
        if (($sale->sale_type === 'credit_sale' && $sale->payment_basis !== 'credit') || ($sale->sale_type === 'cash_sale' && $sale->payment_basis !== 'cash')) {
            throw new RegistryConflictException('Sale type and payment basis must agree.');
        }
        $sale->branch_id = $input['branch_id'] ?? $sale->branch_id;
        $sale->customer_id = $input['customer_id'] ?? $sale->customer_id;
        $sale->currency_id = $this->currency($input['currency_id'] ?? $sale->currency_id ?? $company->default_currency_id, $company)->id;
        $sale->payment_term_id = $sale->payment_basis === 'credit' ? $this->paymentTerm($input['payment_term_id'] ?? $sale->payment_term_id ?? $company->default_payment_term_id, $company)->id : null;
        $sale->customer_reference = $input['customer_reference'] ?? $sale->customer_reference;
        $sale->channel = $input['channel'] ?? $sale->channel;
        $sale->notes = $input['notes'] ?? $sale->notes;
        $sale->document_discount_type = $input['document_discount_type'] ?? null;
        $sale->document_discount_value = $input['document_discount_value'] ?? 0;
        if ($sale->payment_basis === 'credit') {
            $this->customer($sale->customer_id, $company, true);
            $term = PaymentTerm::find($sale->payment_term_id);
            $sale->due_date = $this->dueDate($sale->sale_date, $term, $input['due_date'] ?? null, $company);
        } else {
            $sale->customer_id = $input['customer_id'] ?? null;
            $sale->due_date = null;
        }
    }

    private function writeLines(Sale $sale, array $lines, Company $company, Request $request): void
    {
        foreach ($lines as $lineInput) {
            $item = ProductService::where('company_id', $company->id)->whereKey($lineInput['product_service_id'])->where('status', 'active')->where('sellable', true)->with('baseUnit')->first();
            if (! $item) {
                throw new RegistryConflictException('Every Sale line must reference an active same-company sellable Product or Service.', ['dependency' => 'product_service']);
            }
            $this->inventory->validateSaleLineLocation($company, $item, $lineInput);
            $quantity = (string) $lineInput['quantity'];
            if (bccomp($quantity, '0', 6) <= 0) {
                throw new RegistryConflictException('Sale quantities must be positive.');
            }
            if ($item->baseUnit && ! $item->baseUnit->allows_fractional && bccomp($quantity, (string) (int) $quantity, 6) !== 0) {
                throw new RegistryConflictException('The selected Unit of Measure does not allow fractional quantities.', ['product_service_id' => $item->id]);
            }
            $hasExplicitPrice = array_key_exists('unit_price', $lineInput) && $lineInput['unit_price'] !== null;
            $unitPrice = $hasExplicitPrice ? (string) $lineInput['unit_price'] : (string) ($item->standard_selling_price ?? '0');
            if ($item->standard_selling_price === null && ! $hasExplicitPrice) {
                throw new RegistryConflictException('A standard selling price or an authorized price override is required.', ['dependency' => 'pricing']);
            }
            if ($hasExplicitPrice && $item->standard_selling_price !== null && bccomp($unitPrice, (string) $item->standard_selling_price, 6) !== 0 && (! $request->user()?->hasPermission('sales.price-override', $company->id) || empty($lineInput['price_override_reason']))) {
                throw new RegistryConflictException('A price override requires the Sales price-override permission and a reason.', ['dependency' => 'price_override']);
            }
            if (bccomp($unitPrice, '0', 6) < 0) {
                throw new RegistryConflictException('Sale prices cannot be negative.');
            }
            $gross = bcmul($quantity, $unitPrice, 6);
            $discountValue = (string) ($lineInput['discount_value'] ?? 0);
            $discountType = $lineInput['discount_type'] ?? null;
            $discount = $discountType === 'percent' ? bcdiv(bcmul($gross, $discountValue, 6), '100', 6) : ($discountType === 'amount' ? $discountValue : '0');
            if (bccomp($discount, $gross, 6) > 0) {
                throw new RegistryConflictException('A line discount cannot exceed the line amount.');
            }
            $tax = isset($lineInput['tax_code_id']) ? TaxCode::where('company_id', $company->id)->whereKey($lineInput['tax_code_id'])->where('status', 'active')->first() : null;
            if (isset($lineInput['tax_code_id']) && ! $tax) {
                throw new RegistryConflictException('The selected Tax Code is not active in this company.', ['dependency' => 'tax_code']);
            }
            $netBeforeTax = bcsub($gross, $discount, 6);
            $taxRate = $tax?->rate ?? '0';
            $taxAmount = $tax && $tax->basis === 'inclusive' ? bcsub($netBeforeTax, bcdiv($netBeforeTax, bcadd('1', bcdiv((string) $taxRate, '100', 6), 6), 6), 6) : ($tax ? bcdiv(bcmul($netBeforeTax, (string) $taxRate, 6), '100', 6) : '0');
            $net = $tax && $tax->basis === 'exclusive' ? bcadd($netBeforeTax, $taxAmount, 6) : $netBeforeTax;
            SaleLine::create(['id' => (string) Str::uuid(), 'sale_id' => $sale->id, 'company_id' => $company->id, 'product_service_id' => $item->id, 'warehouse_id' => $lineInput['warehouse_id'] ?? null, 'stock_location_id' => $lineInput['stock_location_id'] ?? null, 'item_type' => $item->record_type, 'item_code_snapshot' => $item->code, 'description_snapshot' => $lineInput['description'] ?? $item->name, 'unit_code' => $item->baseUnit?->code, 'unit_name' => $item->baseUnit?->name, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'gross_amount' => $gross, 'discount_type' => $discountType, 'discount_value' => $discountValue, 'discount_amount' => $discount, 'tax_code_id' => $tax?->id, 'tax_code_snapshot' => $tax?->code, 'tax_basis' => $tax?->basis, 'tax_rate' => $taxRate, 'taxable_amount' => $netBeforeTax, 'tax_amount' => $taxAmount, 'net_amount' => $net, 'stock_managed_snapshot' => (bool) $item->stock_managed, 'non_stock_snapshot' => (bool) $item->non_stock, 'service_snapshot' => $item->record_type === 'service', 'version' => 1]);
        }
    }

    private function recalculateStoredSale(Sale $sale, array $input, Company $company, Request $request): void
    {
        $lines = $sale->lines()->get();
        if ($lines->isEmpty()) {
            throw new RegistryConflictException('A Sale must contain at least one line.');
        }
        $subtotal = $lines->sum(fn ($line) => (string) $line->gross_amount);
        $lineDiscount = $lines->sum(fn ($line) => (string) $line->discount_amount);
        $beforeDocument = bcsub((string) $subtotal, (string) $lineDiscount, 6);
        $documentValue = (string) ($sale->document_discount_value ?? 0);
        $documentDiscount = $sale->document_discount_type === 'percent' ? bcdiv(bcmul($beforeDocument, $documentValue, 6), '100', 6) : ($sale->document_discount_type === 'amount' ? min($documentValue, $beforeDocument) : '0');
        $taxable = '0';
        $tax = '0';
        $total = '0';
        $allocated = '0';
        $last = $lines->count() - 1;
        foreach ($lines->values() as $index => $line) {
            $base = bcsub((string) $line->gross_amount, (string) $line->discount_amount, 6);
            $allocation = $index === $last ? bcsub($documentDiscount, $allocated, 6) : ($beforeDocument === '0' ? '0' : bcdiv(bcmul($documentDiscount, $base, 6), $beforeDocument, 6));
            $allocated = bcadd($allocated, $allocation, 6);
            $lineTaxable = bcsub($base, $allocation, 6);
            $rate = (string) ($line->tax_rate ?? 0);
            $lineTax = $line->tax_basis === 'inclusive' ? bcsub($lineTaxable, bcdiv($lineTaxable, bcadd('1', bcdiv($rate, '100', 6), 6), 6), 6) : ($rate === '0' ? '0' : bcdiv(bcmul($lineTaxable, $rate, 6), '100', 6));
            $lineTotal = $line->tax_basis === 'inclusive' ? $lineTaxable : bcadd($lineTaxable, $lineTax, 6);
            $line->taxable_amount = $lineTaxable;
            $line->tax_amount = $lineTax;
            $line->net_amount = $lineTotal;
            $line->save();
            $taxable = bcadd($taxable, $lineTaxable, 6);
            $tax = bcadd($tax, $lineTax, 6);
            $total = bcadd($total, $lineTotal, 6);
        }
        $sale->subtotal = $subtotal;
        $sale->line_discount_total = $lineDiscount;
        $sale->document_discount_total = $documentDiscount;
        $sale->taxable_amount = $taxable;
        $sale->tax_total = $tax;
        $sale->total = $total;
        $sale->paid_amount = '0';
        $sale->receivable_amount = $sale->payment_basis === 'credit' ? $total : '0';
        $sale->remaining_amount = $sale->receivable_amount;
        $sale->due_status = $this->dueStatus($sale->due_date, $company);
        $sale->save();
    }

    private function post(Sale $sale, Company $company, Request $request, bool $allowCash = false): string
    {
        if ($sale->status !== 'approved') {
            throw new RegistryConflictException('Only Approved Sales can be posted.', ['status' => $sale->status]);
        }
        if ($sale->payment_basis === 'cash' && ! $allowCash) {
            $this->block($sale, 'collections', 'Cash Sales require Collections and a successful receipt before posting.');
        }
        if ($sale->lines->contains(fn ($line) => $line->stock_managed_snapshot)) {
            $this->inventoryCompletion->consumeSaleReservations($sale, $company, $request);
            $this->inventory->postSaleIssues($sale, $company, $request);
        }
        $accounts = $this->postingAccounts($company, (float) $sale->tax_total > 0);
        $transactionType = $allowCash ? 'cash_sale' : 'credit_sale';
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => $transactionType, 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $sale->sale_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => 'sale:'.$sale->id]);
        $accountingId = (string) Str::uuid();
        DB::table('accounting_transactions')->insert(['id' => $accountingId, 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => $transactionType, 'status' => 'posted', 'business_date' => $sale->sale_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
        $currency = $sale->currency()->firstOrFail();
        $this->accountingLine($accountingId, $accounts['receivable']->id, $sale->total, '0', $currency->code, 'Receivable for '.$sale->sale_number);
        $revenue = bcsub((string) $sale->total, (string) $sale->tax_total, 6);
        $this->accountingLine($accountingId, $accounts['revenue']->id, '0', $revenue, $currency->code, 'Sales revenue for '.$sale->sale_number);
        if (bccomp((string) $sale->tax_total, '0', 6) > 0) {
            $this->accountingLine($accountingId, $accounts['tax']->id, '0', $sale->tax_total, $currency->code, 'Sales tax for '.$sale->sale_number);
        }
        $receivable = ReceivableOpenItem::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'customer_id' => $sale->customer_id, 'source_sale_id' => $sale->id, 'source_document_number' => $sale->sale_number, 'currency_id' => $sale->currency_id, 'original_amount' => $sale->total, 'remaining_amount' => $sale->total, 'due_date' => $sale->due_date, 'settlement_status' => 'unpaid', 'due_status' => $sale->due_status, 'dispute_status' => 'not_disputed', 'last_calculated_at' => now(), 'version' => 1]);
        if ($allowCash) {
            $sale->receivable_amount = $sale->total;
            $sale->remaining_amount = $sale->total;
            $sale->settlement_status = 'unpaid';
        }
        $sale->status = 'posted';
        $sale->posted_by = $request->user()?->id;
        $sale->posted_at = now();
        $sale->business_transaction_id = $business->id;
        $sale->accounting_transaction_id = $accountingId;
        $sale->version++;
        $sale->blocked_code = null;
        $sale->blocked_reason = null;
        $sale->idempotency_identity = $request->header('Idempotency-Key') ?? $sale->idempotency_identity;
        $sale->save();

        return 'posted';
    }

    private function postingAccounts(Company $company, bool $needsTax): array
    {
        $active = fn (string $classification) => AccountTitle::where('company_id', $company->id)->where('classification', $classification)->where('status', 'active')->where('posting_eligible', true);
        $receivable = (clone $active('asset'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%receivable%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%receivable%'])->orWhereRaw('LOWER(code) LIKE ?', ['%receivable%']))->first();
        $revenue = (clone $active('income'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%revenue%'])->orWhereRaw('LOWER(name) LIKE ?', ['%sales%'])->orWhereRaw('LOWER(name) LIKE ?', ['%income%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%revenue%']))->first();
        $tax = $needsTax ? (clone $active('liability'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%tax%'])->orWhereRaw('LOWER(name) LIKE ?', ['%vat%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%tax%']))->first() : null;
        if (! $receivable || ! $revenue || ($needsTax && ! $tax)) {
            throw new RegistryConflictException('Sales posting is blocked until active posting Account Titles are configured for Receivables, Sales Revenue, and applicable Tax.', ['dependency' => 'account_titles', 'receivable' => ! (bool) $receivable, 'revenue' => ! (bool) $revenue, 'tax' => $needsTax && ! $tax]);
        }

        return compact('receivable', 'revenue', 'tax');
    }

    private function accountingLine(string $transactionId, string $accountId, string $debit, string $credit, string $currency, string $description): void
    {
        DB::table('accounting_transaction_lines')->insert(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $transactionId, 'account_title_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'currency_code' => $currency, 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function review(Sale $sale, ?int $actor): ?string
    {
        if ($sale->status !== 'for_approval') {
            throw new RegistryConflictException('Only Sales waiting for approval can be reviewed.');
        } $sale->reviewed_by = $actor;
        $sale->reviewed_at = now();
        $sale->version++;
        $sale->save();

        return 'for_approval';
    }

    private function approve(Sale $sale, ?int $actor): string
    {
        if ($sale->status !== 'for_approval') {
            throw new RegistryConflictException('Only Sales waiting for approval can be approved.');
        } if ($actor && in_array($actor, array_filter([$sale->created_by, $sale->submitted_by]), true)) {
            throw new RegistryConflictException('The preparer cannot approve the same Sale.');
        } $sale->approved_by = $actor;
        $sale->approved_at = now();
        $sale->status = 'approved';
        $sale->version++;
        $sale->save();

        return 'approved';
    }

    private function cancel(Sale $sale, ?int $actor, ?string $reason): string
    {
        if (in_array($sale->status, ['posted', 'reversed', 'cancelled'], true)) {
            throw new RegistryConflictException('This Sale cannot be cancelled from its current status.');
        } if (! $reason) {
            throw new RegistryConflictException('A cancellation reason is required.');
        } $sale->status = 'cancelled';
        $sale->cancelled_by = $actor;
        $sale->cancelled_at = now();
        $sale->cancellation_reason = $reason;
        $sale->version++;
        $sale->save();

        return 'cancelled';
    }

    private function transitionTo(Sale $sale, string $from, string $to): string
    {
        if ($sale->status !== $from) {
            throw new RegistryConflictException('This Sale cannot be submitted from its current status.', ['status' => $sale->status]);
        } $sale->status = $to;
        $sale->submitted_by = request()->user()?->id;
        $sale->submitted_at = now();
        $sale->version++;
        $sale->save();

        return $to;
    }

    private function block(Sale $sale, string $code, string $reason): never
    {
        $sale->status = 'failed';
        $sale->blocked_code = $code;
        $sale->blocked_reason = $reason;
        $sale->version++;
        $sale->save();
        throw new RegistryConflictException($reason, ['dependency' => $code]);
    }

    private function assertVersion(Sale $sale, array $input): void
    {
        if (array_key_exists('version', $input) && (int) $input['version'] !== (int) $sale->version) {
            throw new RegistryConflictException('This Sale was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
    }

    private function currency(?string $id, Company $company): ReferenceCurrency
    {
        $currency = ReferenceCurrency::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->first();
        if (! $currency) {
            throw new RegistryConflictException('An active same-company Currency is required.', ['dependency' => 'currency']);
        }

        return $currency;
    }

    private function paymentTerm(?string $id, Company $company): PaymentTerm
    {
        $term = PaymentTerm::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->first();
        if (! $term) {
            throw new RegistryConflictException('An active same-company Payment Term is required for credit sales.', ['dependency' => 'payment_term']);
        }

        return $term;
    }

    private function customer(?string $id, Company $company, bool $required): ?BusinessPartner
    {
        if (! $id && ! $required) {
            return null;
        } $customer = BusinessPartner::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'customer')->where('status', 'active'))->first();
        if (! $customer) {
            throw new RegistryConflictException('An active same-company customer is required for a credit Sale.', ['dependency' => 'customer']);
        }

        return $customer;
    }

    private function dueDate($date, ?PaymentTerm $term, ?string $provided, Company $company): ?string
    {
        if ($term?->term_type === 'due_date') {
            if (! $provided || Carbon::parse($provided)->lt(Carbon::parse($date))) {
                throw new RegistryConflictException('A due date on or after the Sale date is required.');
            }

            return $provided;
        } $due = Carbon::parse($date, $company->timezone ?: config('app.timezone'));
        if (($term?->due_days ?? 0) > 0) {
            $due->addDays($term->due_days);
        } if ($term?->end_of_month) {
            $due->endOfMonth();
        }

        return $due->toDateString();
    }

    private function dueStatus($dueDate, Company $company): string
    {
        if (! $dueDate) {
            return 'no_due_date';
        } $due = Carbon::parse($dueDate);
        $today = Carbon::today($company->timezone ?: config('app.timezone'));

        return $due->lt($today) ? 'overdue' : ($due->equalTo($today) ? 'due_today' : 'not_yet_due');
    }

    private function audit(Request $request, string $action, Sale|BillingStatement $record, array $before, array $after, string $description): void
    {
        $this->audit->record($request, $action, $record, $record->company_id, $before, $after, null, 'Sales & Receivables', $description);
    }

    private function returnForCorrection(Sale $sale, ?int $actor, ?string $reason): string
    {
        if ($sale->status !== 'for_approval') {
            throw new RegistryConflictException('Only Sales waiting for approval can be returned for correction.');
        }
        if (! $reason) {
            throw new RegistryConflictException('A return-for-correction reason is required.');
        }
        $sale->status = 'draft';
        $sale->submitted_by = null;
        $sale->submitted_at = null;
        $sale->reviewed_by = $actor;
        $sale->reviewed_at = now();
        $sale->version++;
        $sale->save();

        return 'draft';
    }
}
