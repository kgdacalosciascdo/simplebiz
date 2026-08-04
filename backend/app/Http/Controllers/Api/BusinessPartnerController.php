<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterRegistries\StoreAddressRequest;
use App\Http\Requests\MasterRegistries\StoreBusinessPartnerRequest;
use App\Http\Requests\MasterRegistries\StoreContactRequest;
use App\Http\Requests\MasterRegistries\UpdateBusinessPartnerRequest;
use App\Http\Resources\MasterRegistries\BusinessPartnerResource;
use App\Models\BusinessPartner;
use App\Services\MasterRegistryService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class BusinessPartnerController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly MasterRegistryService $service, private readonly IdempotencyService $idempotency) {}

    public function index(Request $request)
    {
        $query = BusinessPartner::where('company_id', $this->context->id())->with('roles')->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')), fn ($q) => $q->where('status', 'active'))->when($request->filled('role'), fn ($q) => $q->whereHas('roles', fn ($role) => $role->where('role', $request->string('role'))->where('status', 'active')))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('display_name', 'ilike', '%'.$request->string('q').'%')->orWhere('code', 'ilike', '%'.$request->string('q').'%')->orWhere('primary_email', 'ilike', '%'.$request->string('q').'%')));
        $sort = in_array($request->string('sort')->toString(), ['display_name', 'code', 'updated_at', 'status'], true) ? $request->string('sort')->toString() : 'display_name';
        $page = $query->orderBy($sort)->paginate(min((int) $request->input('per_page', 25), 100));

        return ApiResponse::success(BusinessPartnerResource::collection($page)->resolve(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(string $businessPartner)
    {
        $record = BusinessPartner::where('company_id', $this->context->id())->with(['roles', 'contacts', 'addresses'])->whereKey($businessPartner)->firstOrFail();

        return ApiResponse::success((new BusinessPartnerResource($record))->resolve());
    }

    public function store(StoreBusinessPartnerRequest $request)
    {
        $input = $request->validated();

        return $this->idempotency->run($request, 'master.business_partner.create', $this->context->id(), fn () => ApiResponse::success((new BusinessPartnerResource($this->service->createBusinessPartner($input, $this->context->get(), $request)))->resolve(), 201));
    }

    public function quickCreate(StoreBusinessPartnerRequest $request)
    {
        $input = [...$request->validated(), 'source_channel' => 'contextual_quick_create'];

        return $this->idempotency->run($request, 'master.business_partner.quick_create', $this->context->id(), fn () => ApiResponse::success((new BusinessPartnerResource($this->service->createBusinessPartner($input, $this->context->get(), $request)))->resolve(), 201));
    }

    public function update(UpdateBusinessPartnerRequest $request, string $businessPartner)
    {
        $record = BusinessPartner::where('company_id', $this->context->id())->whereKey($businessPartner)->firstOrFail();

        return ApiResponse::success((new BusinessPartnerResource($this->service->updateBusinessPartner($record, $request->validated(), $this->context->get(), $request)))->resolve());
    }

    public function deactivate(Request $request, string $businessPartner)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = BusinessPartner::where('company_id', $this->context->id())->whereKey($businessPartner)->firstOrFail();

        return $this->idempotency->run($request, 'master.business_partner.deactivate', $this->context->id(), fn () => ApiResponse::success((new BusinessPartnerResource($this->service->transition($record, 'business_partner', 'inactive', $input['reason'], $this->context->get(), $request)))->resolve()));
    }

    public function reactivate(Request $request, string $businessPartner)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = BusinessPartner::where('company_id', $this->context->id())->whereKey($businessPartner)->firstOrFail();

        return $this->idempotency->run($request, 'master.business_partner.reactivate', $this->context->id(), fn () => ApiResponse::success((new BusinessPartnerResource($this->service->transition($record, 'business_partner', 'active', $input['reason'], $this->context->get(), $request)))->resolve()));
    }

    public function history(string $businessPartner)
    {
        BusinessPartner::where('company_id', $this->context->id())->whereKey($businessPartner)->firstOrFail();
        $page = $this->service->history('business_partner', $businessPartner, $this->context->get());

        return ApiResponse::success($page->items(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function contact(StoreContactRequest $request, string $businessPartner)
    {
        $partner = BusinessPartner::where('company_id', $this->context->id())->whereKey($businessPartner)->firstOrFail();

        return ApiResponse::success($this->service->createContact($partner, $request->validated(), $this->context->get(), $request), 201);
    }

    public function address(StoreAddressRequest $request, string $businessPartner)
    {
        $partner = BusinessPartner::where('company_id', $this->context->id())->whereKey($businessPartner)->firstOrFail();

        return ApiResponse::success($this->service->createAddress($partner, $request->validated(), $this->context->get(), $request), 201);
    }
}
