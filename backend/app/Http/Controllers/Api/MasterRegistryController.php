<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountTitle;
use App\Models\ActivityEvent;
use App\Models\Branch;
use App\Models\BusinessPartner;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\ProductService;
use App\Models\ReasonCode;
use App\Models\ReferenceCurrency;
use App\Models\RegistryCategory;
use App\Models\RegistryHistory;
use App\Models\StockLocation;
use App\Models\TaxCode;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MasterRegistryController extends Controller
{
    public function summary(Request $request)
    {
        $companyId = $request->attributes->get('company')->id;
        $models = [BusinessPartner::class, ProductService::class, RegistryCategory::class, UnitOfMeasure::class, ReferenceCurrency::class, PaymentMethod::class, PaymentTerm::class, TaxCode::class, AccountTitle::class, ExpenseCategory::class, Branch::class, Warehouse::class, StockLocation::class, ReasonCode::class];
        $total = 0;
        $active = 0;
        foreach ($models as $model) {
            $total += $model::where('company_id', $companyId)->count();
            $active += $model::where('company_id', $companyId)->where('status', 'active')->count();
        }

        return ApiResponse::success(['counts' => ['business_partners' => BusinessPartner::where('company_id', $companyId)->where('status', 'active')->count(), 'customers' => BusinessPartner::where('company_id', $companyId)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'customer')->where('status', 'active'))->count(), 'suppliers' => BusinessPartner::where('company_id', $companyId)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'supplier')->where('status', 'active'))->count(), 'products' => ProductService::where('company_id', $companyId)->where('record_type', 'product')->where('status', 'active')->count(), 'services' => ProductService::where('company_id', $companyId)->where('record_type', 'service')->where('status', 'active')->count(), 'categories' => RegistryCategory::where('company_id', $companyId)->where('status', 'active')->count(), 'units' => UnitOfMeasure::where('company_id', $companyId)->where('status', 'active')->count()], 'registry_summary' => ['total_records' => $total, 'active_records' => $active, 'inactive_records' => $total - $active, 'updated_today' => RegistryHistory::where('company_id', $companyId)->whereDate('created_at', now()->toDateString())->count()], 'recent_activity' => ActivityEvent::where('company_id', $companyId)->whereIn('event', ['business_partner.created', 'business_partner.updated', 'product_service.created', 'product_service.updated', 'category.created', 'unit.created', 'currency.created', 'payment_method.created', 'branch.created', 'warehouse.created'])->latest('occurred_at')->limit(8)->get(['id', 'event', 'title', 'description', 'entity_id', 'occurred_at'])]);
    }

    public function lookups(Request $request)
    {
        $companyId = $request->attributes->get('company')->id;
        $search = trim((string) $request->input('q', ''));
        $limit = min(max((int) $request->input('limit', 50), 1), 100);
        $active = fn ($query) => $query->where('status', 'active')->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()));
        $like = '%'.strtolower($search).'%';
        $partners = BusinessPartner::where('company_id', $companyId)->tap($active)->when($request->filled('role'), fn ($q) => $q->whereHas('roles', fn ($role) => $role->where('role', $request->string('role'))->where('status', 'active')))->when($search !== '', fn ($q) => $q->where(fn ($searchQuery) => $searchQuery->whereRaw('LOWER(display_name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like])))->orderBy('display_name')->limit($limit)->get(['id', 'code', 'display_name']);
        $items = ProductService::where('company_id', $companyId)->tap($active)->when($request->filled('type'), fn ($q) => $q->where('record_type', $request->string('type')))->when($search !== '', fn ($q) => $q->where(fn ($searchQuery) => $searchQuery->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(barcode) LIKE ?', [$like])))->orderBy('name')->limit($limit)->get(['id', 'code', 'name', 'record_type', 'base_unit_id']);
        $categories = RegistryCategory::where('company_id', $companyId)->tap($active)->when($search !== '', fn ($q) => $q->where(fn ($searchQuery) => $searchQuery->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like])))->orderBy('display_order')->orderBy('name')->limit($limit)->get(['id', 'code', 'name', 'applicability']);
        $units = UnitOfMeasure::where('company_id', $companyId)->tap($active)->when($search !== '', fn ($q) => $q->where(fn ($searchQuery) => $searchQuery->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(symbol) LIKE ?', [$like])))->orderBy('name')->limit($limit)->get(['id', 'code', 'name', 'symbol', 'decimal_precision']);

        return ApiResponse::success(['business_partners' => $partners, 'products_services' => $items, 'categories' => $categories, 'units' => $units]);
    }
}
