<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterRegistries\StoreReferenceRequest;
use App\Http\Requests\MasterRegistries\UpdateReferenceRequest;
use App\Http\Resources\MasterRegistries\ReferenceResource;
use App\Models\AccountTitle;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\ReasonCode;
use App\Models\ReferenceCurrency;
use App\Models\RegistryHistory;
use App\Models\StockLocation;
use App\Models\TaxCode;
use App\Models\Warehouse;
use App\Services\ReferenceRegistryService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ReferenceRegistryController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly ReferenceRegistryService $service, private readonly IdempotencyService $idempotency) {}

    public function currencies(Request $request)
    {
        return $this->list(ReferenceCurrency::class, $request);
    }

    public function paymentMethods(Request $request)
    {
        return $this->list(PaymentMethod::class, $request);
    }

    public function paymentTerms(Request $request)
    {
        return $this->list(PaymentTerm::class, $request);
    }

    public function taxCodes(Request $request)
    {
        return $this->list(TaxCode::class, $request);
    }

    public function accountTitles(Request $request)
    {
        return $this->list(AccountTitle::class, $request);
    }

    public function expenseCategories(Request $request)
    {
        return $this->list(ExpenseCategory::class, $request);
    }

    public function branches(Request $request)
    {
        return $this->list(Branch::class, $request);
    }

    public function warehouses(Request $request)
    {
        return $this->list(Warehouse::class, $request);
    }

    public function stockLocations(Request $request)
    {
        return $this->list(StockLocation::class, $request);
    }

    public function reasonCodes(Request $request)
    {
        return $this->list(ReasonCode::class, $request);
    }

    public function storeCurrency(StoreReferenceRequest $request)
    {
        return $this->create($request, 'currency', fn ($input) => $this->service->createCurrency($input, $this->context->get(), $request));
    }

    public function storePaymentMethod(StoreReferenceRequest $request)
    {
        return $this->create($request, 'payment_method', fn ($input) => $this->service->createPaymentMethod($input, $this->context->get(), $request));
    }

    public function storePaymentTerm(StoreReferenceRequest $request)
    {
        return $this->create($request, 'payment_term', fn ($input) => $this->service->createPaymentTerm($input, $this->context->get(), $request));
    }

    public function storeTaxCode(StoreReferenceRequest $request)
    {
        return $this->create($request, 'tax_code', fn ($input) => $this->service->createTaxCode($input, $this->context->get(), $request));
    }

    public function storeAccountTitle(StoreReferenceRequest $request)
    {
        return $this->create($request, 'account_title', fn ($input) => $this->service->createAccountTitle($input, $this->context->get(), $request));
    }

    public function storeExpenseCategory(StoreReferenceRequest $request)
    {
        return $this->create($request, 'expense_category', fn ($input) => $this->service->createExpenseCategory($input, $this->context->get(), $request));
    }

    public function storeBranch(StoreReferenceRequest $request)
    {
        return $this->create($request, 'branch', fn ($input) => $this->service->createBranch($input, $this->context->get(), $request));
    }

    public function storeWarehouse(StoreReferenceRequest $request)
    {
        return $this->create($request, 'warehouse', fn ($input) => $this->service->createWarehouse($input, $this->context->get(), $request));
    }

    public function storeStockLocation(StoreReferenceRequest $request)
    {
        return $this->create($request, 'stock_location', fn ($input) => $this->service->createStockLocation($input, $this->context->get(), $request));
    }

    public function storeReasonCode(StoreReferenceRequest $request)
    {
        return $this->create($request, 'reason_code', fn ($input) => $this->service->createReasonCode($input, $this->context->get(), $request));
    }

    public function updateCurrency(UpdateReferenceRequest $request, string $id)
    {
        return $this->update($request, ReferenceCurrency::class, 'currency', $id);
    }

    public function updatePaymentMethod(UpdateReferenceRequest $request, string $id)
    {
        return $this->update($request, PaymentMethod::class, 'payment_method', $id);
    }

    public function updatePaymentTerm(UpdateReferenceRequest $request, string $id)
    {
        return $this->update($request, PaymentTerm::class, 'payment_term', $id);
    }

    public function updateTaxCode(UpdateReferenceRequest $request, string $id)
    {
        return $this->update($request, TaxCode::class, 'tax_code', $id);
    }

    public function updateAccountTitle(UpdateReferenceRequest $request, string $id)
    {
        return $this->update($request, AccountTitle::class, 'account_title', $id);
    }

    public function updateExpenseCategory(UpdateReferenceRequest $request, string $id)
    {
        return $this->update($request, ExpenseCategory::class, 'expense_category', $id);
    }

    public function updateBranch(UpdateReferenceRequest $request, string $id)
    {
        return $this->update($request, Branch::class, 'branch', $id);
    }

    public function updateWarehouse(UpdateReferenceRequest $request, string $id)
    {
        return $this->update($request, Warehouse::class, 'warehouse', $id);
    }

    public function updateStockLocation(UpdateReferenceRequest $request, string $id)
    {
        return $this->update($request, StockLocation::class, 'stock_location', $id);
    }

    public function updateReasonCode(UpdateReferenceRequest $request, string $id)
    {
        return $this->update($request, ReasonCode::class, 'reason_code', $id);
    }

    public function showCurrency(string $id)
    {
        return $this->show(ReferenceCurrency::class, $id);
    }

    public function showPaymentMethod(string $id)
    {
        return $this->show(PaymentMethod::class, $id);
    }

    public function showPaymentTerm(string $id)
    {
        return $this->show(PaymentTerm::class, $id);
    }

    public function showTaxCode(string $id)
    {
        return $this->show(TaxCode::class, $id);
    }

    public function showAccountTitle(string $id)
    {
        return $this->show(AccountTitle::class, $id);
    }

    public function showExpenseCategory(string $id)
    {
        return $this->show(ExpenseCategory::class, $id);
    }

    public function showBranch(string $id)
    {
        return $this->show(Branch::class, $id);
    }

    public function showWarehouse(string $id)
    {
        return $this->show(Warehouse::class, $id);
    }

    public function showStockLocation(string $id)
    {
        return $this->show(StockLocation::class, $id);
    }

    public function showReasonCode(string $id)
    {
        return $this->show(ReasonCode::class, $id);
    }

    public function deactivateCurrency(Request $request, string $id)
    {
        return $this->transition($request, 'currency', $id, 'inactive');
    }

    public function reactivateCurrency(Request $request, string $id)
    {
        return $this->transition($request, 'currency', $id, 'active');
    }

    public function deactivatePaymentMethod(Request $request, string $id)
    {
        return $this->transition($request, 'payment_method', $id, 'inactive');
    }

    public function reactivatePaymentMethod(Request $request, string $id)
    {
        return $this->transition($request, 'payment_method', $id, 'active');
    }

    public function deactivatePaymentTerm(Request $request, string $id)
    {
        return $this->transition($request, 'payment_term', $id, 'inactive');
    }

    public function reactivatePaymentTerm(Request $request, string $id)
    {
        return $this->transition($request, 'payment_term', $id, 'active');
    }

    public function deactivateTaxCode(Request $request, string $id)
    {
        return $this->transition($request, 'tax_code', $id, 'inactive');
    }

    public function reactivateTaxCode(Request $request, string $id)
    {
        return $this->transition($request, 'tax_code', $id, 'active');
    }

    public function deactivateAccountTitle(Request $request, string $id)
    {
        return $this->transition($request, 'account_title', $id, 'inactive');
    }

    public function reactivateAccountTitle(Request $request, string $id)
    {
        return $this->transition($request, 'account_title', $id, 'active');
    }

    public function deactivateExpenseCategory(Request $request, string $id)
    {
        return $this->transition($request, 'expense_category', $id, 'inactive');
    }

    public function reactivateExpenseCategory(Request $request, string $id)
    {
        return $this->transition($request, 'expense_category', $id, 'active');
    }

    public function deactivateBranch(Request $request, string $id)
    {
        return $this->transition($request, 'branch', $id, 'inactive');
    }

    public function reactivateBranch(Request $request, string $id)
    {
        return $this->transition($request, 'branch', $id, 'active');
    }

    public function deactivateWarehouse(Request $request, string $id)
    {
        return $this->transition($request, 'warehouse', $id, 'inactive');
    }

    public function reactivateWarehouse(Request $request, string $id)
    {
        return $this->transition($request, 'warehouse', $id, 'active');
    }

    public function deactivateStockLocation(Request $request, string $id)
    {
        return $this->transition($request, 'stock_location', $id, 'inactive');
    }

    public function reactivateStockLocation(Request $request, string $id)
    {
        return $this->transition($request, 'stock_location', $id, 'active');
    }

    public function deactivateReasonCode(Request $request, string $id)
    {
        return $this->transition($request, 'reason_code', $id, 'inactive');
    }

    public function reactivateReasonCode(Request $request, string $id)
    {
        return $this->transition($request, 'reason_code', $id, 'active');
    }

    public function historyCurrency(string $id)
    {
        return $this->historyFor('currency', $id);
    }

    public function historyPaymentMethod(string $id)
    {
        return $this->historyFor('payment_method', $id);
    }

    public function historyPaymentTerm(string $id)
    {
        return $this->historyFor('payment_term', $id);
    }

    public function historyTaxCode(string $id)
    {
        return $this->historyFor('tax_code', $id);
    }

    public function historyAccountTitle(string $id)
    {
        return $this->historyFor('account_title', $id);
    }

    public function historyExpenseCategory(string $id)
    {
        return $this->historyFor('expense_category', $id);
    }

    public function historyBranch(string $id)
    {
        return $this->historyFor('branch', $id);
    }

    public function historyWarehouse(string $id)
    {
        return $this->historyFor('warehouse', $id);
    }

    public function historyStockLocation(string $id)
    {
        return $this->historyFor('stock_location', $id);
    }

    public function historyReasonCode(string $id)
    {
        return $this->historyFor('reason_code', $id);
    }

    public function deactivate(Request $request, string $type, string $id)
    {
        return $this->transition($request, $type, $id, 'inactive');
    }

    public function reactivate(Request $request, string $type, string $id)
    {
        return $this->transition($request, $type, $id, 'active');
    }

    public function history(string $type, string $id)
    {
        $record = $this->findType($type, $id);
        $page = RegistryHistory::where('company_id', $this->context->id())->where('registry_type', $type)->where('record_id', $record->id)->latest('created_at')->paginate(25);

        return ApiResponse::success($page->items(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function lookups(Request $request)
    {
        $companyId = $this->context->id();
        $search = trim((string) $request->input('q', ''));
        $limit = min(max((int) $request->input('limit', 50), 1), 100);
        $active = fn ($query) => $query->where('status', 'active')->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()));

        $matches = fn ($query, array $fields) => $query->when($search !== '', fn ($q) => $q->where(function ($searchQuery) use ($fields, $search) {
            $like = '%'.strtolower($search).'%';
            foreach ($fields as $field) {
                $searchQuery->orWhereRaw('LOWER('.$field.') LIKE ?', [$like]);
            }
        }));

        return ApiResponse::success(['currencies' => $matches(ReferenceCurrency::where('company_id', $companyId)->tap($active), ['code', 'name', 'symbol'])->orderBy('code')->limit($limit)->get(['id', 'code', 'name', 'symbol', 'decimal_precision']), 'payment_methods' => $matches(PaymentMethod::where('company_id', $companyId)->tap($active)->when($request->filled('direction'), fn ($q) => $request->string('direction')->toString() === 'incoming' ? $q->where('supports_incoming', true) : $q->where('supports_outgoing', true)), ['code', 'name', 'method_class'])->orderBy('name')->limit($limit)->get(['id', 'code', 'name', 'method_class', 'supports_incoming', 'supports_outgoing']), 'payment_terms' => $matches(PaymentTerm::where('company_id', $companyId)->tap($active), ['code', 'name'])->orderBy('name')->limit($limit)->get(['id', 'code', 'name', 'term_type', 'due_days']), 'tax_codes' => $matches(TaxCode::where('company_id', $companyId)->tap($active), ['code', 'name', 'tax_type'])->orderBy('code')->limit($limit)->get(['id', 'code', 'name', 'tax_type', 'rate', 'basis']), 'account_titles' => $matches(AccountTitle::where('company_id', $companyId)->tap($active)->when($request->filled('classification'), fn ($q) => $q->where('classification', $request->string('classification'))), ['code', 'name', 'classification'])->orderBy('code')->limit($limit)->get(['id', 'code', 'name', 'classification', 'normal_balance']), 'expense_categories' => $matches(ExpenseCategory::where('company_id', $companyId)->with('accountTitle')->tap($active), ['code', 'name'])->orderBy('name')->limit($limit)->get(['id', 'code', 'name', 'account_title_id']), 'branches' => $matches(Branch::where('company_id', $companyId)->tap($active), ['code', 'name'])->orderBy('name')->limit($limit)->get(['id', 'code', 'name']), 'warehouses' => $matches(Warehouse::where('company_id', $companyId)->with('branch')->tap($active)->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id'))), ['code', 'name'])->orderBy('name')->limit($limit)->get(['id', 'branch_id', 'code', 'name']), 'stock_locations' => $matches(StockLocation::where('company_id', $companyId)->with('warehouse')->tap($active)->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->string('warehouse_id'))), ['code', 'name'])->orderBy('name')->limit($limit)->get(['id', 'warehouse_id', 'parent_id', 'code', 'name']), 'reason_codes' => $matches(ReasonCode::where('company_id', $companyId)->tap($active)->when($request->filled('domain'), fn ($q) => $q->where('domain', $request->string('domain'))), ['code', 'name', 'domain'])->orderBy('domain')->orderBy('name')->limit($limit)->get(['id', 'code', 'name', 'domain', 'requires_explanation', 'requires_evidence'])]);
    }

    private function list(string $class, Request $request)
    {
        $query = $class::where('company_id', $this->context->id())->when($class === ExpenseCategory::class, fn ($q) => $q->with('accountTitle'))->when($class === Warehouse::class, fn ($q) => $q->with('branch'))->when($class === StockLocation::class, fn ($q) => $q->with('warehouse'))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')), fn ($q) => $q->where('status', 'active'))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('name', 'ilike', '%'.$request->string('q').'%')->orWhere('code', 'ilike', '%'.$request->string('q').'%')));
        if ($class === PaymentMethod::class && $request->filled('direction')) {
            $query->where($request->string('direction')->toString() === 'incoming' ? 'supports_incoming' : 'supports_outgoing', true);
        }
        if ($class === TaxCode::class && $request->filled('tax_type')) {
            $query->where('tax_type', $request->string('tax_type'));
        }
        if ($class === AccountTitle::class && $request->filled('classification')) {
            $query->where('classification', $request->string('classification'));
        }
        if ($class === Warehouse::class && $request->filled('branch_id')) {
            $query->where('branch_id', $request->string('branch_id'));
        }
        if ($class === StockLocation::class && $request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->string('warehouse_id'));
        }
        if ($class === ReasonCode::class && $request->filled('domain')) {
            $query->where('domain', $request->string('domain'));
        }
        $sort = in_array($request->string('sort')->toString(), ['code', 'name', 'status', 'updated_at'], true) ? $request->string('sort')->toString() : 'name';
        $page = $query->orderBy($sort)->paginate(min((int) $request->input('per_page', 25), 100));

        return ApiResponse::success(ReferenceResource::collection($page)->resolve(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    private function create(StoreReferenceRequest $request, string $type, \Closure $action)
    {
        return $this->idempotency->run($request, 'master.'.$type.'.create', $this->context->id(), fn () => ApiResponse::success((new ReferenceResource($action($request->validated())))->resolve(), 201));
    }

    private function update(UpdateReferenceRequest $request, string $class, string $type, string $id)
    {
        $record = $this->find($class, $id);
        $updated = $this->service->update($record, $type, $request->validated(), $this->context->get(), $request);

        return ApiResponse::success((new ReferenceResource($updated))->resolve());
    }

    private function show(string $class, string $id)
    {
        return ApiResponse::success((new ReferenceResource($this->find($class, $id)))->resolve());
    }

    private function transition(Request $request, string $type, string $id, string $status)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = $this->findType($type, $id);

        return $this->idempotency->run($request, 'master.'.$type.'.'.($status === 'active' ? 'reactivate' : 'deactivate'), $this->context->id(), fn () => ApiResponse::success((new ReferenceResource($this->service->transition($record, $type, $status, $input['reason'], $this->context->get(), $request)))->resolve()));
    }

    private function find(string $class, string $id): Model
    {
        return $class::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }

    private function historyFor(string $type, string $id)
    {
        $record = $this->findType($type, $id);
        $page = RegistryHistory::where('company_id', $this->context->id())->where('registry_type', $type)->where('record_id', $record->id)->latest('created_at')->paginate(25);

        return ApiResponse::success($page->items(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    private function findType(string $type, string $id): Model
    {
        $classes = ['currency' => ReferenceCurrency::class, 'payment_method' => PaymentMethod::class, 'payment_term' => PaymentTerm::class, 'tax_code' => TaxCode::class, 'account_title' => AccountTitle::class, 'expense_category' => ExpenseCategory::class, 'branch' => Branch::class, 'warehouse' => Warehouse::class, 'stock_location' => StockLocation::class, 'reason_code' => ReasonCode::class];
        if (! isset($classes[$type])) {
            abort(404);
        }

        return $this->find($classes[$type], $id);
    }
}
