<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccounts\DispositionRequest;
use App\Http\Requests\CashAccounts\EvidenceRequest;
use App\Http\Requests\CashAccounts\ReasonRequest;
use App\Http\Requests\CashAccounts\SaveCashCountAttemptRequest;
use App\Http\Requests\CashAccounts\StoreCashCountRequest;
use App\Http\Requests\CashAccounts\StoreCashDenominationRequest;
use App\Http\Resources\CashAccounts\AttachmentResource;
use App\Http\Resources\CashAccounts\CashAdjustmentResource;
use App\Http\Resources\CashAccounts\CashCountAttemptResource;
use App\Http\Resources\CashAccounts\CashCountResource;
use App\Http\Resources\CashAccounts\CashDenominationResource;
use App\Models\CashAdjustment;
use App\Models\CashCount;
use App\Models\CashCountAttempt;
use App\Models\CashCountType;
use App\Models\CashCountVariance;
use App\Models\CashDenomination;
use App\Models\ReferenceCurrency;
use App\Services\AttachmentService;
use App\Services\CashCountService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CashCountController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly CashCountService $service, private readonly AttachmentService $attachments, private readonly IdempotencyService $idempotency) {}

    public function types()
    {
        return ApiResponse::success(CashCountType::where('status', 'active')->orderBy('name')->get());
    }

    public function denominations(Request $request)
    {
        $query = CashDenomination::where(fn ($builder) => $builder->whereNull('company_id')->orWhere('company_id', $this->context->id()))->with('currency')->where('status', 'active');
        if ($request->filled('currency_id')) {
            $query->where('currency_id', $request->string('currency_id'));
        }

        return CashDenominationResource::collection($query->orderBy('currency_id')->orderBy('sort_order')->orderBy('face_value')->paginate(200));
    }

    public function storeDenomination(StoreCashDenominationRequest $request)
    {
        $currency = ReferenceCurrency::where('company_id', $this->context->id())->whereKey($request->validated('currency_id'))->where('status', 'active')->firstOrFail();
        $input = $request->validated();
        $duplicate = CashDenomination::where('currency_id', $currency->id)->where('status', 'active')->where('face_value', $input['face_value'])->where('display_label', $input['display_label'])->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $this->context->id()))->exists();
        if ($duplicate) {
            return ApiResponse::error('An active denomination with the same value and label already exists for this currency.', 409, ['duplicate' => true]);
        }
        $denomination = CashDenomination::create(['id' => (string) Str::uuid(), 'company_id' => $this->context->id(), 'currency_id' => $currency->id, 'denomination_type' => $input['denomination_type'], 'face_value' => $input['face_value'], 'display_label' => $input['display_label'], 'sort_order' => $input['sort_order'] ?? 0, 'status' => 'active', 'effective_from' => $input['effective_from'] ?? now()->toDateString(), 'effective_to' => $input['effective_to'] ?? null, 'system_standard' => false, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);

        return ApiResponse::success(new CashDenominationResource($denomination->load('currency')), 201);
    }

    public function index(Request $request)
    {
        $query = CashCount::where('company_id', $this->context->id())->with(['account.currency', 'type', 'currency', 'branch', 'variance', 'adjustment', 'currentAttempt']);
        foreach (['status', 'cash_account_id', 'variance_classification', 'current_custodian_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->string($field));
            }
        }
        if ($request->filled('q')) {
            $query->where('count_number', 'like', '%'.$request->string('q').'%');
        }

        return CashCountResource::collection($query->orderByDesc('count_date')->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 50), 100)));
    }

    public function reports(Request $request)
    {
        $counts = CashCount::where('company_id', $this->context->id())->with(['account', 'type', 'variance'])->when($request->filled('cash_account_id'), fn ($query) => $query->where('cash_account_id', $request->string('cash_account_id')))->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))->orderByDesc('count_date')->paginate(min((int) $request->integer('per_page', 50), 100));

        return ApiResponse::success(['counts' => CashCountResource::collection($counts->items())->resolve(), 'variance_summary' => CashCountVariance::where('company_id', $this->context->id())->select(['classification', 'status'])->selectRaw('COUNT(*) as count')->selectRaw('SUM(ABS(variance_amount)) as total')->groupBy('classification', 'status')->get(), 'pagination' => ['current_page' => $counts->currentPage(), 'last_page' => $counts->lastPage(), 'total' => $counts->total()], 'as_of' => now()->toIso8601String()]);
    }

    public function show(string $id)
    {
        return ApiResponse::success(new CashCountResource($this->service->load($this->count($id))));
    }

    public function store(StoreCashCountRequest $request)
    {
        return $this->idempotency->run($request, 'cash-accounts.cash-counts.create', $this->context->id(), fn () => ApiResponse::success(new CashCountResource($this->service->create($request->validated(), $this->context->get(), $request)), 201));
    }

    public function start(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.cash-counts.start', $this->context->id(), fn () => ApiResponse::success(new CashCountResource($this->service->start($this->count($id), $this->context->get(), $request))));
    }

    public function createAttempt(Request $request, string $id)
    {
        $request->validate(['recount_reason' => ['nullable', 'string', 'max:5000']]);

        return $this->idempotency->run($request, 'cash-accounts.cash-counts.attempts', $this->context->id(), fn () => ApiResponse::success(new CashCountAttemptResource($this->service->createAttempt($this->count($id), $request->all(), $this->context->get(), $request)), 201));
    }

    public function saveAttempt(SaveCashCountAttemptRequest $request, string $id, string $attemptId)
    {
        $attempt = $this->attempt($id, $attemptId);

        return $this->idempotency->run($request, 'cash-accounts.cash-counts.attempts.update', $this->context->id(), fn () => ApiResponse::success(new CashCountAttemptResource($this->service->saveAttempt($attempt, $request->validated(), $this->context->get(), $request))));
    }

    public function confirm(Request $request, string $id, string $attemptId)
    {
        $input = $request->validate(['type' => ['required', 'in:witness,custodian'], 'comments' => ['nullable', 'string', 'max:5000']]);
        $confirmation = $this->service->confirm($this->attempt($id, $attemptId), $input['type'], $input['comments'] ?? '', $this->context->get(), $request);

        return ApiResponse::success($confirmation);
    }

    public function submitAttempt(Request $request, string $id, string $attemptId)
    {
        return $this->idempotency->run($request, 'cash-accounts.cash-counts.submit', $this->context->id(), fn () => ApiResponse::success(new CashCountResource($this->service->submitAttempt($this->attempt($id, $attemptId), $this->context->get(), $request))));
    }

    public function review(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.cash-counts.review', $this->context->id(), fn () => ApiResponse::success(new CashCountResource($this->service->review($this->count($id), $this->context->get(), $request))));
    }

    public function approve(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.cash-counts.approve', $this->context->id(), fn () => ApiResponse::success(new CashCountResource($this->service->approve($this->count($id), $this->context->get(), $request))));
    }

    public function disposition(DispositionRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.variances.disposition', $this->context->id(), fn () => ApiResponse::success(new CashCountResource($this->service->disposition($this->count($id), $request->validated(), $this->context->get(), $request))));
    }

    public function recount(Request $request, string $id)
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:5000']]);

        return $this->idempotency->run($request, 'cash-accounts.cash-counts.recount', $this->context->id(), fn () => ApiResponse::success(new CashCountResource($this->service->requestRecount($this->count($id), $input['reason'], $this->context->get(), $request))));
    }

    public function close(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.cash-counts.approve', $this->context->id(), fn () => ApiResponse::success(new CashCountResource($this->service->close($this->count($id), $this->context->get(), $request))));
    }

    public function cancel(ReasonRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.cash-counts.cancel', $this->context->id(), fn () => ApiResponse::success(new CashCountResource($this->service->cancel($this->count($id), $request->validated('reason'), $this->context->get(), $request))));
    }

    public function uploadEvidence(EvidenceRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.cash-counts.evidence.upload', $this->context->id(), fn () => ApiResponse::success(new AttachmentResource($this->attachments->upload($request->file('file'), $this->count($id), $this->context->get(), $request)), 201));
    }

    public function evidence(string $id)
    {
        return AttachmentResource::collection($this->count($id)->attachments()->orderByDesc('created_at')->paginate(50));
    }

    public function approveAdjustment(Request $request, string $adjustmentId)
    {
        return $this->idempotency->run($request, 'cash-accounts.adjustments.approve', $this->context->id(), fn () => ApiResponse::success(new CashAdjustmentResource($this->service->approveAdjustment($this->adjustment($adjustmentId), $this->context->get(), $request))));
    }

    public function postAdjustment(Request $request, string $adjustmentId)
    {
        return $this->idempotency->run($request, 'cash-accounts.adjustments.post', $this->context->id(), fn () => ApiResponse::success(new CashAdjustmentResource($this->service->postAdjustment($this->adjustment($adjustmentId), $this->context->get(), $request))));
    }

    public function reverseAdjustment(ReasonRequest $request, string $adjustmentId)
    {
        return $this->idempotency->run($request, 'cash-accounts.adjustments.reverse', $this->context->id(), fn () => ApiResponse::success(new CashAdjustmentResource($this->service->reverseAdjustment($this->adjustment($adjustmentId), $request->validated('reason'), $this->context->get(), $request))));
    }

    private function count(string $id): CashCount
    {
        return CashCount::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }

    private function attempt(string $countId, string $attemptId): CashCountAttempt
    {
        return CashCountAttempt::where('cash_count_id', $this->count($countId)->id)->whereKey($attemptId)->firstOrFail()->load('count.type');
    }

    private function adjustment(string $id): CashAdjustment
    {
        return CashAdjustment::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
    }
}
