<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ReferenceCurrency;
use App\Models\ReportAnalyticsDefinition;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * MDS-100 read-only composition boundary.
 *
 * This class selects and presents owner-module projections. It deliberately
 * does not maintain dashboard totals or implement operational formulas.
 */
final class DashboardService
{
    public function __construct(
        private readonly CollectionsService $collections,
        private readonly SalesService $sales,
        private readonly PurchasingService $purchases,
        private readonly PaymentService $payments,
        private readonly PaymentCompletionService $paymentCompletion,
        private readonly InventoryService $inventory,
        private readonly InventoryCompletionService $inventoryCompletion,
        private readonly ExpenseService $expenses,
        private readonly CashPositionService $cashPositions,
        private readonly ReportsService $reports,
    ) {}

    public function compose(Company $company, Request $request): array
    {
        $context = $this->context($company, $request);
        $sourceRequest = clone $request;
        $sourceRequest->merge([
            'from' => $context['period']['from'],
            'to' => $context['period']['to'],
            'as_of' => $context['as_of']['date'],
            'per_page' => 50,
        ]);
        if ($context['branch']['selected']['id'] ?? null) {
            $sourceRequest->merge(['branch_id' => $context['branch']['selected']['id']]);
        }
        if ($context['currency']['selected'] ?? null) {
            $sourceRequest->merge(['currency' => $context['currency']['selected'], 'currency_code' => $context['currency']['selected']]);
        }

        $sources = [
            'sales' => $this->source($request, $company, 'MDS-200', 'sales.view', fn () => $this->sales->dashboard($company, $sourceRequest), 'period'),
            'sales_attention' => $this->source($request, $company, 'MDS-200', 'sales.view', fn () => $this->sales->attention($company), 'as_of'),
            'collections' => $this->source($request, $company, 'MDS-300', 'collections.view', fn () => $this->collections->summary($company), 'source_defined'),
            'purchases' => $this->source($request, $company, 'MDS-400', 'purchases.view', fn () => $this->purchases->summary($company), 'source_defined'),
            'purchases_attention' => $this->source($request, $company, 'MDS-400', 'purchases.view', fn () => $this->purchases->attention($company), 'as_of'),
            'payments' => $this->source($request, $company, 'MDS-500', 'payments.view', fn () => $this->payments->summary($company), 'as_of'),
            'payments_attention' => $this->source($request, $company, 'MDS-500', 'payments.view', fn () => $this->paymentCompletion->attention($company), 'as_of'),
            'inventory' => $this->source($request, $company, 'MDS-600', 'inventory.view', fn () => $this->inventory->summary($company), 'as_of'),
            'inventory_attention' => $this->source($request, $company, 'MDS-600', 'inventory.view', fn () => ['items' => $this->inventoryCompletion->conditions($company)], 'as_of'),
            'cash' => $this->source($request, $company, 'MDS-700', 'cash-accounts.view', fn () => $this->cashPositions->dashboard($company), 'as_of'),
            'expenses' => $this->source($request, $company, 'MDS-800', 'expenses.view', fn () => $this->expenses->summary($company), 'source_defined'),
            'expenses_attention' => $this->source($request, $company, 'MDS-800', 'expenses.view', fn () => $this->expenses->attention($company), 'as_of'),
            'business_performance' => $this->source($request, $company, 'MDS-900', 'reports.analytics.view', fn () => $this->reports->businessPerformanceForDashboard($company, $sourceRequest), 'period'),
        ];

        $cashMovement = $this->source($request, $company, 'MDS-700', 'cash-accounts.view', fn () => $this->cashMovement($company, $sourceRequest), 'period');
        $sources['cash_movement'] = $cashMovement;

        return [
            'context' => $context,
            'sources' => $sources,
            'kpis' => $this->kpis($sources, $context),
            'attention' => $this->attention($request, $company, $sources),
            'activity' => $this->activity($request, $company),
            'quick_actions' => $this->quickActions($request, $company),
            'reports' => $this->reports($request, $company),
            'refresh' => ['generated_at' => now($company->timezone)->toISOString(), 'strategy' => 'parallel-safe source projections; refresh is read-only'],
        ];
    }

    private function context(Company $company, Request $request): array
    {
        $timezone = $company->timezone ?: config('app.timezone');
        $today = Carbon::now($timezone)->startOfDay();
        $preset = strtolower((string) $request->input('period', 'month'));
        $from = null;
        $to = null;

        if ($preset === 'custom') {
            $from = $this->date($request->input('from'), $timezone, 'from');
            $to = $this->date($request->input('to'), $timezone, 'to');
            if (! $from || ! $to) {
                throw ValidationException::withMessages(['period' => 'Custom dashboard periods require both from and to dates.']);
            }
        } else {
            [$from, $to] = match ($preset) {
                'today' => [$today->copy(), $today->copy()],
                'week', 'this_week' => [$today->copy()->startOfWeek(), $today->copy()],
                'month', 'this_month' => [$today->copy()->startOfMonth(), $today->copy()],
                'quarter', 'this_quarter' => [$today->copy()->startOfQuarter(), $today->copy()],
                'year', 'this_year' => [$today->copy()->startOfYear(), $today->copy()],
                default => throw ValidationException::withMessages(['period' => 'Choose today, week, month, quarter, year, or custom.']),
            };
        }
        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['period' => 'The dashboard period must end on or after it starts.']);
        }

        $branchId = $request->input('branch_id');
        $branch = $branchId ? Branch::where('company_id', $company->id)->where('status', 'active')->whereKey($branchId)->first() : null;
        if ($branchId && ! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'The selected branch is not active in the current company.']);
        }
        if ($branch) {
            throw ValidationException::withMessages(['branch_id' => 'Branch-scoped dashboard projections are not available until every owner source contract supports the selected branch.']);
        }
        $currencyCode = $request->filled('currency') ? strtoupper((string) $request->input('currency')) : null;
        if ($currencyCode && ! ReferenceCurrency::where('company_id', $company->id)->where('status', 'active')->where('code', $currencyCode)->exists()) {
            throw ValidationException::withMessages(['currency' => 'The selected currency is not active in the current company.']);
        }

        $branches = Branch::where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']);
        $currencies = ReferenceCurrency::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['code', 'name', 'symbol']);

        return [
            'company' => ['id' => $company->id, 'name' => $company->name, 'timezone' => $timezone, 'locale' => $company->locale, 'default_currency' => $company->currency],
            'branch' => ['selected' => null, 'available' => $branches->values()->all(), 'scope' => 'company'],
            'period' => ['preset' => $preset, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $timezone, 'label' => $from->isSameDay($to) ? $from->isoFormat('D MMM YYYY') : $from->isoFormat('D MMM YYYY').' – '.$to->isoFormat('D MMM YYYY')],
            'as_of' => ['date' => $to->toDateString(), 'resolved_at' => Carbon::now($timezone)->toISOString(), 'timezone' => $timezone],
            'currency' => ['selected' => $currencyCode, 'company_default' => $company->currency, 'available' => $currencies->values()->all(), 'rule' => 'Financial values remain separated by source currency; no dashboard FX conversion is applied.'],
        ];
    }

    private function date(mixed $value, string $timezone, string $field): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse((string) $value, $timezone)->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Use a valid calendar date.']);
        }
    }

    private function source(Request $request, Company $company, string $owner, string $permission, callable $loader, string $periodBasis): array
    {
        if (! $request->user()?->hasPermission($permission, (int) $company->id)) {
            return ['status' => 'unauthorized', 'owner' => $owner, 'permission' => $permission, 'period_basis' => $periodBasis, 'freshness_state' => 'unavailable'];
        }
        try {
            $data = $loader();

            return ['status' => 'available', 'owner' => $owner, 'permission' => $permission, 'period_basis' => $periodBasis, 'freshness_state' => $data['freshness_state'] ?? $data['freshness'] ?? 'current', 'as_of_at' => $data['as_of_at'] ?? $data['source_as_of_at'] ?? $data['generated_at'] ?? now()->toISOString(), 'data' => $data];
        } catch (Throwable $exception) {
            Log::warning('Dashboard source unavailable', ['owner' => $owner, 'permission' => $permission, 'company_id' => $company->id, 'exception' => $exception::class]);

            return ['status' => 'unavailable', 'owner' => $owner, 'permission' => $permission, 'period_basis' => $periodBasis, 'freshness_state' => 'unavailable', 'error' => 'The source is temporarily unavailable. Try refreshing this dashboard.'];
        }
    }

    private function kpis(array $sources, array $context): array
    {
        $sales = $sources['sales'];
        $salesMetrics = $sales['status'] === 'available' ? ($sales['data']['metrics'] ?? []) : [];
        $salesValues = collect($salesMetrics)->map(fn ($metric) => ['currency' => data_get($metric, 'currency.code'), 'value' => data_get($metric, 'net_sales'), 'count' => data_get($metric, 'sales_count')])->values()->all();
        $receivableValues = collect($salesMetrics)->map(fn ($metric) => ['currency' => data_get($metric, 'currency.code'), 'value' => data_get($metric, 'open_receivable_amount'), 'overdue' => data_get($metric, 'overdue_amount'), 'due_soon' => data_get($metric, 'due_soon_amount')])->values()->all();
        $collections = $sources['collections'];
        $purchases = $sources['purchases'];
        $payments = $sources['payments'];
        $inventory = $sources['inventory'];
        $cash = $sources['cash'];
        $expenses = $sources['expenses'];

        return [
            $this->kpi('net_sales', 'Net Sales', 'MDS-200', 'sales', $sales, $salesValues, '/sales', $context['period']),
            $this->kpi('collections', 'Collections', 'MDS-300', 'collections', $collections, $collections['status'] === 'available' ? [['currency' => null, 'value' => null, 'posted_receipts' => data_get($collections, 'data.receipts.posted', 0), 'unapplied_count' => data_get($collections, 'data.unapplied.count', 0)]] : [], '/collections', $context['period']),
            $this->kpi('purchases', 'Purchases', 'MDS-400', 'purchases', $purchases, $purchases['status'] === 'available' ? [['currency' => null, 'value' => data_get($purchases, 'data.purchases_this_month'), 'open_orders' => data_get($purchases, 'data.open_purchase_orders', 0), 'open_payables' => data_get($purchases, 'data.open_payables', 0)]] : [], '/purchases', $context['period']),
            $this->kpi('payments', 'Payments', 'MDS-500', 'payments', $payments, $payments['status'] === 'available' ? [['currency' => null, 'value' => data_get($payments, 'data.eligible_amount'), 'eligible_count' => data_get($payments, 'data.eligible_count', 0), 'pending_confirmation' => data_get($payments, 'data.pending_confirmation', 0)]] : [], '/payments', $context['period']),
            $this->kpi('cash_position', 'Cash Position', 'MDS-700', 'cash', $cash, $cash['status'] === 'available' ? collect($cash['data.position_by_currency'] ?? [])->map(fn ($row) => ['currency' => $row['currency'] ?? $row['currency_code'] ?? null, 'value' => $row['posted_balance']])->values()->all() : [], '/cash-accounts', $context['as_of']),
            $this->kpi('receivables', 'Receivables', 'MDS-200', 'sales', $sales, $receivableValues, '/sales?view=receivables', $context['as_of']),
            $this->kpi('payables', 'Payables', 'MDS-400', 'purchases', $purchases, $purchases['status'] === 'available' ? [['currency' => null, 'value' => data_get($purchases, 'data.amount_to_pay'), 'overdue' => data_get($purchases, 'data.overdue'), 'due_soon' => data_get($purchases, 'data.due_soon')]] : [], '/purchases?view=payables', $context['as_of']),
            $this->kpi('inventory', 'Inventory', 'MDS-600', 'inventory', $inventory, $inventory['status'] === 'available' ? [['currency' => null, 'quantity' => data_get($inventory, 'data.available'), 'low_stock' => data_get($inventory, 'data.low_stock', 0), 'out_of_stock' => data_get($inventory, 'data.out_of_stock', 0), 'value' => data_get($inventory, 'data.inventory_value')]] : [], '/inventory', $context['as_of']),
            $this->kpi('expenses', 'Expenses', 'MDS-800', 'expenses', $expenses, $expenses['status'] === 'available' ? collect($expenses['data']['period_totals'] ?? [])->map(fn ($row) => ['currency' => $row['currency'] ?? null, 'value' => $row['amount'] ?? null])->values()->all() : [], '/expenses', $context['period']),
        ];
    }

    private function kpi(string $id, string $label, string $owner, string $source, array $sourceState, array $values, string $route, array $basis): array
    {
        return ['id' => $id, 'label' => $label, 'owner' => $owner, 'source' => $source, 'status' => $sourceState['status'], 'freshness_state' => $sourceState['freshness_state'], 'as_of_at' => $sourceState['as_of_at'] ?? null, 'period_basis' => $sourceState['period_basis'], 'basis' => $basis, 'values' => $values, 'route' => $route, 'message' => $sourceState['status'] === 'available' ? null : ($sourceState['error'] ?? 'This metric is not available for your access scope.')];
    }

    private function attention(Request $request, Company $company, array $sources): array
    {
        $items = [];
        $statuses = [];
        $add = function (array $item) use (&$items): void {
            $key = ($item['owner'] ?? 'unknown').'|'.($item['source_id'] ?? $item['code'] ?? $item['title']);
            foreach ($items as $existing) {
                if ($existing['_key'] === $key) {
                    return;
                }
            }
            $item['_key'] = $key;
            $items[] = $item;
        };
        $mapState = function (string $key, string $owner) use (&$statuses, $sources): void {
            $statuses[$key] = ['owner' => $owner, 'status' => $sources[$key]['status'], 'freshness_state' => $sources[$key]['freshness_state'], 'error' => $sources[$key]['error'] ?? null];
        };

        foreach (['sales' => 'MDS-200', 'collections' => 'MDS-300', 'purchases' => 'MDS-400', 'payments' => 'MDS-500', 'inventory' => 'MDS-600', 'cash' => 'MDS-700', 'expenses' => 'MDS-800'] as $key => $owner) {
            $attentionSource = $key === 'collections' || $key === 'cash' ? $key : $key.'_attention';
            $mapState($attentionSource, $owner);
        }

        if ($sources['sales_attention']['status'] === 'available') {
            foreach (collect($sources['sales_attention']['data']['items'] ?? [])->take(25) as $item) {
                $add(['id' => $item['id'] ?? $this->stableId('sales', $item), 'owner' => 'MDS-200', 'source_id' => $item['source_id'] ?? $item['id'] ?? null, 'title' => $item['title'] ?? 'Sales item requires attention', 'detail' => $item['detail'] ?? null, 'count' => 1, 'severity' => $item['severity'] ?? null, 'route' => $item['route'] ?? '/sales']);
            }
        }
        if ($sources['collections']['status'] === 'available' && (int) data_get($sources['collections'], 'data.unapplied.count', 0) > 0) {
            $add(['id' => 'collections-unapplied', 'owner' => 'MDS-300', 'source_id' => 'unapplied', 'code' => 'unapplied_receipts', 'title' => 'Unapplied customer receipts', 'detail' => 'Review and apply receipts to customer receivables.', 'count' => (int) data_get($sources['collections'], 'data.unapplied.count', 0), 'severity' => 'medium', 'route' => '/collections?view=unapplied']);
        }
        if ($sources['purchases_attention']['status'] === 'available') {
            foreach ($sources['purchases_attention']['data']['items'] ?? [] as $item) {
                if ((int) ($item['count'] ?? 0) > 0) {
                    $add(['id' => 'purchases-'.$item['key'], 'owner' => 'MDS-400', 'source_id' => $item['key'], 'code' => $item['key'], 'title' => $item['title'], 'detail' => 'Review in Purchases & Payables.', 'count' => (int) $item['count'], 'severity' => 'medium', 'route' => '/purchases']);
                }
            }
        }
        if ($sources['payments_attention']['status'] === 'available') {
            foreach ([['pending_approval', 'Payments awaiting approval'], ['pending_confirmation', 'Payments awaiting confirmation'], ['failed', 'Failed or rejected payments'], ['stopped_checks', 'Stopped or stale checks'], ['partial_batches', 'Partially completed payment batches']] as [$field, $title]) {
                $count = (int) data_get($sources['payments_attention'], 'data.'.$field, 0);
                if ($count > 0) {
                    $add(['id' => 'payments-'.$field, 'owner' => 'MDS-500', 'source_id' => $field, 'code' => $field, 'title' => $title, 'detail' => 'Review the Payment workflow.', 'count' => $count, 'severity' => in_array($field, ['failed', 'stopped_checks'], true) ? 'high' : 'medium', 'route' => '/payments']);
                }
            }
        }
        if ($sources['inventory_attention']['status'] === 'available') {
            foreach ($sources['inventory_attention']['data']['items'] ?? [] as $item) {
                $add(['id' => 'inventory-'.$item['rule_id'], 'owner' => 'MDS-600', 'source_id' => $item['product_id'] ?? $item['rule_id'], 'code' => $item['type'] ?? 'stock', 'title' => ($item['type'] ?? 'Stock').' item', 'detail' => $item['product_name'] ?? 'Review inventory availability.', 'count' => 1, 'severity' => $item['severity'] ?? null, 'route' => '/inventory']);
            }
        }
        if ($sources['cash']['status'] === 'available') {
            foreach ($sources['cash']['data']['attention']['items'] ?? [] as $item) {
                $add(['id' => 'cash-'.($item['source_id'] ?? $item['code'] ?? $this->stableId('cash', $item)), 'owner' => 'MDS-700', 'source_id' => $item['source_id'] ?? null, 'code' => $item['code'] ?? null, 'title' => $item['title'] ?? 'Cash Account item requires attention', 'detail' => isset($item['detail']) && is_array($item['detail']) ? ($item['detail']['name'] ?? null) : ($item['detail'] ?? null), 'count' => (int) ($item['count'] ?? 1), 'severity' => $item['severity'] ?? null, 'route' => '/cash-accounts']);
            }
        }
        if ($sources['expenses_attention']['status'] === 'available') {
            foreach (collect($sources['expenses_attention']['data']['items'] ?? [])->take(25) as $item) {
                $add(['id' => 'expense-'.($item['id'] ?? $this->stableId('expense', $item)), 'owner' => 'MDS-800', 'source_id' => $item['id'] ?? null, 'title' => 'Expense requires attention', 'detail' => $item['description'] ?? $item['expense_number'] ?? null, 'count' => 1, 'severity' => 'medium', 'route' => '/expenses']);
            }
        }
        $items = collect($items)->sortBy(fn ($item) => ['high' => 0, 'medium' => 1, 'low' => 2][$item['severity'] ?? 'low'] ?? 3)->map(function ($item) {
            unset($item['_key']);

            return $item;
        })->values()->take(50)->all();

        return ['items' => $items, 'total' => count($items), 'sources' => $statuses, 'generated_at' => now($company->timezone)->toISOString(), 'failure_disclosure' => collect($statuses)->where('status', 'unavailable')->values()->all()];
    }

    private function activity(Request $request, Company $company): array
    {
        $permissions = ['sales' => 'sales.view', 'collections' => 'collections.view', 'purchases' => 'purchases.view', 'payments' => 'payments.view', 'inventory' => 'inventory.view', 'cash' => 'cash-accounts.view', 'expenses' => 'expenses.view', 'reports' => 'reports.view'];
        $allowed = collect($permissions)->filter(fn ($permission) => $request->user()?->hasPermission($permission, (int) $company->id))->keys()->all();
        $rows = ActivityEvent::where('company_id', $company->id)->latest('occurred_at')->limit(80)->get();
        $items = $rows->map(function (ActivityEvent $event) use ($allowed) {
            $source = $this->activitySource($event->event);
            if (! $source || ! in_array($source, $allowed, true)) {
                return null;
            }
            $route = match ($source) {
                'sales' => '/sales', 'collections' => '/collections', 'purchases' => '/purchases', 'payments' => '/payments', 'inventory' => '/inventory', 'cash' => '/cash-accounts', 'expenses' => '/expenses', default => '/reports',
            };

            return ['id' => $event->id, 'source' => 'MDS-'.match ($source) {
                'sales' => '200', 'collections' => '300', 'purchases' => '400', 'payments' => '500', 'inventory' => '600', 'cash' => '700', 'expenses' => '800', default => '900'
            }, 'title' => $event->title, 'description' => $event->description, 'actor_id' => $event->user_id, 'occurred_at' => $event->occurred_at?->toISOString(), 'source_record_id' => $event->entity_id, 'route' => $route];
        })->filter()->values()->take(25)->all();

        return ['status' => 'available', 'items' => $items, 'limit' => 25, 'source_scope' => $allowed, 'generated_at' => now($company->timezone)->toISOString()];
    }

    private function activitySource(string $event): ?string
    {
        $event = strtolower($event);

        return match (true) {
            str_contains($event, 'sales') || str_contains($event, 'sale') => 'sales',
            str_contains($event, 'receipt') || str_contains($event, 'collection') || str_contains($event, 'remittance') => 'collections',
            str_contains($event, 'purchase') || str_contains($event, 'supplier') => 'purchases',
            str_contains($event, 'payment') || str_contains($event, 'payable') => 'payments',
            str_contains($event, 'inventory') || str_contains($event, 'stock') => 'inventory',
            str_contains($event, 'cash') || str_contains($event, 'reconciliation') => 'cash',
            str_contains($event, 'expense') || str_contains($event, 'reimbursement') => 'expenses',
            str_contains($event, 'report') => 'reports',
            default => null,
        };
    }

    private function stableId(string $prefix, array $item): string
    {
        return $prefix.'-'.substr(hash('sha256', serialize($item)), 0, 20);
    }

    private function quickActions(Request $request, Company $company): array
    {
        $actions = [
            ['id' => 'sale', 'label' => 'New Sale', 'permission' => 'sales.create', 'route' => '/sales?mode=new', 'owner' => 'MDS-200'],
            ['id' => 'collection', 'label' => 'Receive Payment', 'permission' => 'collections.receipts.create', 'route' => '/collections?mode=new', 'owner' => 'MDS-300'],
            ['id' => 'purchase', 'label' => 'New Purchase Order', 'permission' => 'purchases.orders.create', 'route' => '/purchases?mode=new', 'owner' => 'MDS-400'],
            ['id' => 'payment', 'label' => 'Prepare Payment', 'permission' => 'payments.create', 'route' => '/payments?mode=new', 'owner' => 'MDS-500'],
            ['id' => 'expense', 'label' => 'Record Expense', 'permission' => 'expenses.create', 'route' => '/expenses?mode=new', 'owner' => 'MDS-800'],
            ['id' => 'cash', 'label' => 'Record Cash Activity', 'permission' => 'cash-accounts.movements.view', 'route' => '/cash-accounts?mode=movement', 'owner' => 'MDS-700'],
        ];

        return collect($actions)->filter(fn ($action) => $request->user()?->hasPermission($action['permission'], (int) $company->id))->values()->take(3)->all();
    }

    private function reports(Request $request, Company $company): array
    {
        if (! $request->user()?->hasPermission('reports.view', (int) $company->id)) {
            return ['status' => 'unauthorized', 'items' => []];
        }
        $items = [
            ['id' => 'REP-MGT-001', 'label' => 'Business Performance', 'route' => '/reports?definition=REP-MGT-001', 'owner' => 'MDS-900'],
            ['id' => 'REP-CAS-001', 'label' => 'Cash Position', 'route' => '/reports?definition=REP-CAS-001', 'owner' => 'MDS-900'],
            ['id' => 'REP-AR-001', 'label' => 'Receivables Aging', 'route' => '/reports?definition=REP-AR-001', 'owner' => 'MDS-900'],
            ['id' => 'REP-CAS-002', 'label' => 'Cash Account Ledger', 'route' => '/reports?definition=REP-CAS-002', 'owner' => 'MDS-900'],
        ];

        return ['status' => 'available', 'items' => $items, 'analytics_definition' => ReportAnalyticsDefinition::where('analytics_key', 'ANL-MGT-001')->where('status', 'published')->first(['analytics_key', 'version', 'code', 'name', 'formula_reference'])];
    }

    private function cashMovement(Company $company, Request $request): array
    {
        $ledger = $this->cashPositions->report('cash_account_ledger', $company, $request);
        $groups = [];
        foreach ($ledger['rows'] ?? [] as $row) {
            $currency = $row['currency'] ?? 'UNKNOWN';
            $groups[$currency] ??= ['currency' => $currency, 'cash_in' => '0', 'cash_out' => '0', 'internal_transfers' => '0', 'net_movement' => '0'];
            $groups[$currency]['cash_in'] = bcadd($groups[$currency]['cash_in'], (string) ($row['inflows'] ?? '0'), 6);
            $groups[$currency]['cash_out'] = bcadd($groups[$currency]['cash_out'], (string) ($row['outflows'] ?? '0'), 6);
            $groups[$currency]['internal_transfers'] = bcadd($groups[$currency]['internal_transfers'], (string) ($row['transfers'] ?? '0'), 6);
            $groups[$currency]['net_movement'] = bcsub($groups[$currency]['cash_in'], $groups[$currency]['cash_out'], 6);
        }

        return ['values' => array_values($groups), 'source_as_of_at' => now()->toISOString(), 'freshness_state' => 'current', 'currency_context' => $ledger['currency_context'] ?? 'Cash movement values remain separated by currency.'];
    }
}
