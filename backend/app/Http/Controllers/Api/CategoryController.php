<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterRegistries\StoreCategoryRequest;
use App\Http\Requests\MasterRegistries\UpdateCategoryRequest;
use App\Http\Resources\MasterRegistries\CategoryResource;
use App\Models\RegistryCategory;
use App\Services\MasterRegistryService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly MasterRegistryService $service, private readonly IdempotencyService $idempotency) {}

    public function index(Request $request)
    {
        $query = RegistryCategory::where('company_id', $this->context->id())->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')), fn ($q) => $q->where('status', 'active'))->when($request->filled('q'), fn ($q) => $q->where(fn ($s) => $s->where('name', 'ilike', '%'.$request->string('q').'%')->orWhere('code', 'ilike', '%'.$request->string('q').'%')));
        $page = $query->orderBy('display_order')->orderBy('name')->paginate(min((int) $request->input('per_page', 50), 100));

        return ApiResponse::success(CategoryResource::collection($page)->resolve(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(string $category)
    {
        return ApiResponse::success((new CategoryResource(RegistryCategory::where('company_id', $this->context->id())->whereKey($category)->firstOrFail()))->resolve());
    }

    public function store(StoreCategoryRequest $request)
    {
        return $this->idempotency->run($request, 'master.category.create', $this->context->id(), fn () => ApiResponse::success((new CategoryResource($this->service->createCategory($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function update(UpdateCategoryRequest $request, string $category)
    {
        $record = RegistryCategory::where('company_id', $this->context->id())->whereKey($category)->firstOrFail();

        return ApiResponse::success((new CategoryResource($this->service->updateCategory($record, $request->validated(), $this->context->get(), $request)))->resolve());
    }

    public function deactivate(Request $request, string $category)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = RegistryCategory::where('company_id', $this->context->id())->whereKey($category)->firstOrFail();

        return $this->idempotency->run($request, 'master.category.deactivate', $this->context->id(), fn () => ApiResponse::success((new CategoryResource($this->service->transition($record, 'category', 'inactive', $input['reason'], $this->context->get(), $request)))->resolve()));
    }

    public function reactivate(Request $request, string $category)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $record = RegistryCategory::where('company_id', $this->context->id())->whereKey($category)->firstOrFail();

        return $this->idempotency->run($request, 'master.category.reactivate', $this->context->id(), fn () => ApiResponse::success((new CategoryResource($this->service->transition($record, 'category', 'active', $input['reason'], $this->context->get(), $request)))->resolve()));
    }

    public function history(string $category)
    {
        RegistryCategory::where('company_id', $this->context->id())->whereKey($category)->firstOrFail();
        $page = $this->service->history('category', $category, $this->context->get());

        return ApiResponse::success($page->items(), 200, ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }
}
