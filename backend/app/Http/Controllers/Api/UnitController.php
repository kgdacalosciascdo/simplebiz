<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterRegistries\StoreUnitRequest;
use App\Http\Requests\MasterRegistries\UpdateUnitRequest;
use App\Http\Resources\MasterRegistries\UnitResource;
use App\Models\UnitOfMeasure;
use App\Services\MasterRegistryService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly MasterRegistryService $service, private readonly IdempotencyService $idempotency) {}

    public function index(Request $request)
    {
        $query = UnitOfMeasure::where('company_id', $this->context->id())->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')), fn ($q) => $q->where('status', 'active'))->when($request->filled('q'), fn ($q) => $q->where(fn ($s) => $s->where('name', 'ilike', '%'.$request->string('q').'%')->orWhere('code', 'ilike', '%'.$request->string('q').'%')));
        $page = $query->orderBy('name')->paginate(min((int) $request->input('per_page', 50), 100));

        return ApiResponse::success(UnitResource::collection($page)->resolve(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(string $unit)
    {
        return ApiResponse::success((new UnitResource(UnitOfMeasure::where('company_id', $this->context->id())->whereKey($unit)->firstOrFail()))->resolve());
    }

    public function store(StoreUnitRequest $request)
    {
        return $this->idempotency->run($request, 'master.unit.create', $this->context->id(), fn () => ApiResponse::success((new UnitResource($this->service->createUnit($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function update(UpdateUnitRequest $request, string $unit)
    {
        $record = UnitOfMeasure::where('company_id', $this->context->id())->whereKey($unit)->firstOrFail();

        return ApiResponse::success((new UnitResource($this->service->updateUnit($record, $request->validated(), $this->context->get(), $request)))->resolve());
    }

    public function deactivate(Request $request, string $unit)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = UnitOfMeasure::where('company_id', $this->context->id())->whereKey($unit)->firstOrFail();

        return $this->idempotency->run($request, 'master.unit.deactivate', $this->context->id(), fn () => ApiResponse::success((new UnitResource($this->service->transition($record, 'unit', 'inactive', $input['reason'], $this->context->get(), $request)))->resolve()));
    }

    public function reactivate(Request $request, string $unit)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = UnitOfMeasure::where('company_id', $this->context->id())->whereKey($unit)->firstOrFail();

        return $this->idempotency->run($request, 'master.unit.reactivate', $this->context->id(), fn () => ApiResponse::success((new UnitResource($this->service->transition($record, 'unit', 'active', $input['reason'], $this->context->get(), $request)))->resolve()));
    }

    public function history(string $unit)
    {
        UnitOfMeasure::where('company_id', $this->context->id())->whereKey($unit)->firstOrFail();
        $page = $this->service->history('unit', $unit, $this->context->get());

        return ApiResponse::success($page->items(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }
}
