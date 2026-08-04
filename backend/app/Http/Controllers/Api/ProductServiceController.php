<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterRegistries\StoreProductServiceRequest;
use App\Http\Requests\MasterRegistries\UpdateProductServiceRequest;
use App\Http\Resources\MasterRegistries\ProductServiceResource;
use App\Models\ProductService;
use App\Services\MasterRegistryService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class ProductServiceController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly MasterRegistryService $service, private readonly IdempotencyService $idempotency) {}

    public function index(Request $request)
    {
        $query = ProductService::where('company_id', $this->context->id())->with(['category', 'baseUnit'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')), fn ($q) => $q->where('status', 'active'))->when($request->filled('type'), fn ($q) => $q->where('record_type', $request->string('type')))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('name', 'ilike', '%'.$request->string('q').'%')->orWhere('code', 'ilike', '%'.$request->string('q').'%')->orWhere('barcode', 'ilike', '%'.$request->string('q').'%')));
        $sort = in_array($request->string('sort')->toString(), ['name', 'code', 'updated_at', 'status'], true) ? $request->string('sort')->toString() : 'name';
        $page = $query->orderBy($sort)->paginate(min((int) $request->input('per_page', 25), 100));

        return ApiResponse::success(ProductServiceResource::collection($page)->resolve(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(string $productService)
    {
        $record = ProductService::where('company_id', $this->context->id())->with(['category', 'baseUnit'])->whereKey($productService)->firstOrFail();

        return ApiResponse::success((new ProductServiceResource($record))->resolve());
    }

    public function store(StoreProductServiceRequest $request)
    {
        $input = $request->validated();

        return $this->idempotency->run($request, 'master.product_service.create', $this->context->id(), fn () => ApiResponse::success((new ProductServiceResource($this->service->createProduct($input, $this->context->get(), $request)))->resolve(), 201));
    }

    public function quickCreate(StoreProductServiceRequest $request)
    {
        $input = [...$request->validated(), 'source_channel' => 'contextual_quick_create'];

        return $this->idempotency->run($request, 'master.product_service.quick_create', $this->context->id(), fn () => ApiResponse::success((new ProductServiceResource($this->service->createProduct($input, $this->context->get(), $request)))->resolve(), 201));
    }

    public function update(UpdateProductServiceRequest $request, string $productService)
    {
        $record = ProductService::where('company_id', $this->context->id())->whereKey($productService)->firstOrFail();

        return ApiResponse::success((new ProductServiceResource($this->service->updateProduct($record, $request->validated(), $this->context->get(), $request)))->resolve());
    }

    public function deactivate(Request $request, string $productService)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = ProductService::where('company_id', $this->context->id())->whereKey($productService)->firstOrFail();

        return $this->idempotency->run($request, 'master.product_service.deactivate', $this->context->id(), fn () => ApiResponse::success((new ProductServiceResource($this->service->transition($record, 'product_service', 'inactive', $input['reason'], $this->context->get(), $request)))->resolve()));
    }

    public function reactivate(Request $request, string $productService)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = ProductService::where('company_id', $this->context->id())->whereKey($productService)->firstOrFail();

        return $this->idempotency->run($request, 'master.product_service.reactivate', $this->context->id(), fn () => ApiResponse::success((new ProductServiceResource($this->service->transition($record, 'product_service', 'active', $input['reason'], $this->context->get(), $request)))->resolve()));
    }

    public function history(string $productService)
    {
        ProductService::where('company_id', $this->context->id())->whereKey($productService)->firstOrFail();
        $page = $this->service->history('product_service', $productService, $this->context->get());

        return ApiResponse::success($page->items(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }
}
