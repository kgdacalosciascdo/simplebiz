<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterRegistries\StoreAddressRequest;
use App\Http\Requests\MasterRegistries\StoreBusinessPartnerRequest;
use App\Http\Requests\MasterRegistries\StoreContactRequest;
use App\Http\Requests\MasterRegistries\StoreExternalIdentifierRequest;
use App\Http\Requests\MasterRegistries\UpdateAddressRequest;
use App\Http\Requests\MasterRegistries\UpdateBusinessPartnerRequest;
use App\Http\Requests\MasterRegistries\UpdateContactRequest;
use App\Http\Requests\MasterRegistries\UpdateExternalIdentifierRequest;
use App\Http\Resources\MasterRegistries\BusinessPartnerAddressResource;
use App\Http\Resources\MasterRegistries\BusinessPartnerContactResource;
use App\Http\Resources\MasterRegistries\BusinessPartnerResource;
use App\Http\Resources\MasterRegistries\RegistryExternalIdentifierResource;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerAddress;
use App\Models\BusinessPartnerContact;
use App\Models\RegistryExternalIdentifier;
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
        $record = BusinessPartner::where('company_id', $this->context->id())->with(['roles', 'contacts', 'addresses', 'externalIdentifiers'])->whereKey($businessPartner)->firstOrFail();

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

    public function updateContact(UpdateContactRequest $request, string $businessPartner, string $contact)
    {
        $record = BusinessPartnerContact::where('company_id', $this->context->id())->where('business_partner_id', $businessPartner)->whereKey($contact)->firstOrFail();

        return ApiResponse::success((new BusinessPartnerContactResource($this->service->updateContact($record, $request->validated(), $this->context->get(), $request)))->resolve());
    }

    public function updateAddress(UpdateAddressRequest $request, string $businessPartner, string $address)
    {
        $record = BusinessPartnerAddress::where('company_id', $this->context->id())->where('business_partner_id', $businessPartner)->whereKey($address)->firstOrFail();

        return ApiResponse::success((new BusinessPartnerAddressResource($this->service->updateAddress($record, $request->validated(), $this->context->get(), $request)))->resolve());
    }

    public function transitionContact(Request $request, string $businessPartner, string $contact, string $status)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = BusinessPartnerContact::where('company_id', $this->context->id())->where('business_partner_id', $businessPartner)->whereKey($contact)->firstOrFail();

        return ApiResponse::success((new BusinessPartnerContactResource($this->service->transitionContact($record, $status, $input['reason'], $this->context->get(), $request)))->resolve());
    }

    public function transitionAddress(Request $request, string $businessPartner, string $address, string $status)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = BusinessPartnerAddress::where('company_id', $this->context->id())->where('business_partner_id', $businessPartner)->whereKey($address)->firstOrFail();

        return ApiResponse::success((new BusinessPartnerAddressResource($this->service->transitionAddress($record, $status, $input['reason'], $this->context->get(), $request)))->resolve());
    }

    public function historyContact(string $businessPartner, string $contact)
    {
        BusinessPartnerContact::where('company_id', $this->context->id())->where('business_partner_id', $businessPartner)->whereKey($contact)->firstOrFail();
        $page = $this->service->history('business_partner_contact', $contact, $this->context->get());

        return ApiResponse::success($page->items(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function historyAddress(string $businessPartner, string $address)
    {
        BusinessPartnerAddress::where('company_id', $this->context->id())->where('business_partner_id', $businessPartner)->whereKey($address)->firstOrFail();
        $page = $this->service->history('business_partner_address', $address, $this->context->get());

        return ApiResponse::success($page->items(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function identifiers(string $businessPartner)
    {
        BusinessPartner::where('company_id', $this->context->id())->whereKey($businessPartner)->firstOrFail();

        return ApiResponse::success(RegistryExternalIdentifierResource::collection(RegistryExternalIdentifier::where('company_id', $this->context->id())->where('registry_type', 'business_partner')->where('record_id', $businessPartner)->orderBy('identifier_type')->get())->resolve());
    }

    public function storeIdentifier(StoreExternalIdentifierRequest $request, string $businessPartner)
    {
        $partner = BusinessPartner::where('company_id', $this->context->id())->whereKey($businessPartner)->firstOrFail();

        return $this->idempotency->run($request, 'master.business_partner.identifier.create', $this->context->id(), fn () => ApiResponse::success((new RegistryExternalIdentifierResource($this->service->createExternalIdentifier($partner, 'business_partner', $request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function updateIdentifier(UpdateExternalIdentifierRequest $request, string $businessPartner, string $identifier)
    {
        $record = RegistryExternalIdentifier::where('company_id', $this->context->id())->where('registry_type', 'business_partner')->where('record_id', $businessPartner)->whereKey($identifier)->firstOrFail();

        return ApiResponse::success((new RegistryExternalIdentifierResource($this->service->updateExternalIdentifier($record, $request->validated(), $this->context->get(), $request)))->resolve());
    }

    public function transitionIdentifier(Request $request, string $businessPartner, string $identifier, string $status)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = RegistryExternalIdentifier::where('company_id', $this->context->id())->where('registry_type', 'business_partner')->where('record_id', $businessPartner)->whereKey($identifier)->firstOrFail();

        return ApiResponse::success((new RegistryExternalIdentifierResource($this->service->transitionExternalIdentifier($record, $status, $input['reason'], $this->context->get(), $request)))->resolve());
    }

    public function identifierHistory(string $businessPartner, string $identifier)
    {
        BusinessPartner::where('company_id', $this->context->id())->whereKey($businessPartner)->firstOrFail();
        $page = $this->service->historyExternalIdentifier($identifier, $this->context->get(), 'business_partner');

        return ApiResponse::success($page->items(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }
}
