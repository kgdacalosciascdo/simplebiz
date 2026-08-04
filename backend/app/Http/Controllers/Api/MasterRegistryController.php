<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\BusinessPartner;
use App\Models\ProductService;
use App\Models\RegistryCategory;
use App\Models\UnitOfMeasure;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MasterRegistryController extends Controller
{
    public function summary(Request $request)
    {
        $companyId = $request->attributes->get('company')->id;

        return ApiResponse::success(['counts' => ['business_partners' => BusinessPartner::where('company_id', $companyId)->where('status', 'active')->count(), 'customers' => BusinessPartner::where('company_id', $companyId)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'customer')->where('status', 'active'))->count(), 'suppliers' => BusinessPartner::where('company_id', $companyId)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'supplier')->where('status', 'active'))->count(), 'products' => ProductService::where('company_id', $companyId)->where('record_type', 'product')->where('status', 'active')->count(), 'services' => ProductService::where('company_id', $companyId)->where('record_type', 'service')->where('status', 'active')->count(), 'categories' => RegistryCategory::where('company_id', $companyId)->where('status', 'active')->count(), 'units' => UnitOfMeasure::where('company_id', $companyId)->where('status', 'active')->count()], 'recent_activity' => ActivityEvent::where('company_id', $companyId)->whereIn('event', ['business_partner.created', 'product_service.created', 'category.created', 'unit.created'])->latest('occurred_at')->limit(8)->get(['id', 'event', 'title', 'description', 'entity_id', 'occurred_at'])]);
    }

    public function lookups(Request $request)
    {
        $companyId = $request->attributes->get('company')->id;
        $active = fn ($query) => $query->where('status', 'active')->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString()))->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()));
        $partners = BusinessPartner::where('company_id', $companyId)->tap($active)->when($request->filled('role'), fn ($q) => $q->whereHas('roles', fn ($role) => $role->where('role', $request->string('role'))->where('status', 'active')))->orderBy('display_name')->limit(50)->get(['id', 'code', 'display_name']);
        $items = ProductService::where('company_id', $companyId)->tap($active)->when($request->filled('type'), fn ($q) => $q->where('record_type', $request->string('type')))->orderBy('name')->limit(50)->get(['id', 'code', 'name', 'record_type', 'base_unit_id']);
        $categories = RegistryCategory::where('company_id', $companyId)->tap($active)->orderBy('display_order')->orderBy('name')->limit(50)->get(['id', 'code', 'name', 'applicability']);
        $units = UnitOfMeasure::where('company_id', $companyId)->tap($active)->orderBy('name')->limit(50)->get(['id', 'code', 'name', 'symbol', 'decimal_precision']);

        return ApiResponse::success(['business_partners' => $partners, 'products_services' => $items, 'categories' => $categories, 'units' => $units]);
    }
}
