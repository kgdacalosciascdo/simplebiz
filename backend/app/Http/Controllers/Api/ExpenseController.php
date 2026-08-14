<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\ExpenseActionRequest;
use App\Http\Requests\Expenses\ExpensePaymentRequest;
use App\Http\Requests\Expenses\StoreExpenseRequest;
use App\Http\Resources\Expenses\ExpenseEvidenceResource;
use App\Http\Resources\Expenses\ExpenseObligationResource;
use App\Http\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseEvidence;
use App\Models\ExpenseObligation;
use App\Services\AttachmentService;
use App\Services\ExpenseService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly ExpenseService $service, private readonly AttachmentService $attachments, private readonly IdempotencyService $idempotency) {}

    public function lookups()
    {
        return ApiResponse::success($this->service->lookups($this->context->get()));
    }

    public function summary()
    {
        return ApiResponse::success($this->service->summary($this->context->get()));
    }

    public function index(Request $request)
    {
        [$items, $meta] = $this->service->listHistory($this->context->get(), $request);

        return ApiResponse::success(ExpenseResource::collection($items)->resolve(), 200, $meta);
    }

    public function history(Request $request)
    {
        return $this->index($request);
    }

    public function unpaid(Request $request)
    {
        [$items, $meta] = $this->service->unpaid($this->context->get(), $request);

        return ApiResponse::success(ExpenseObligationResource::collection($items)->resolve(), 200, $meta);
    }

    public function attention()
    {
        return ApiResponse::success($this->service->attention($this->context->get()));
    }

    public function store(StoreExpenseRequest $request)
    {
        return $this->idempotency->run($request, 'expenses.create', $this->context->id(), fn () => ApiResponse::success((new ExpenseResource($this->service->createDraft($request->validated(), $this->context->get(), $request)))->resolve(), 201));
    }

    public function calculate(StoreExpenseRequest $request)
    {
        return ApiResponse::success($this->service->calculate($request->validated(), $this->context->get(), $request));
    }

    public function show(string $id)
    {
        return ApiResponse::success((new ExpenseResource($this->service->show($id, $this->context->get())))->resolve());
    }

    public function update(StoreExpenseRequest $request, string $id)
    {
        $expense = Expense::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'expenses.update', $this->context->id(), fn () => ApiResponse::success((new ExpenseResource($this->service->updateDraft($expense, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function action(ExpenseActionRequest $request, string $id)
    {
        $expense = Expense::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $action = (string) $request->route('action');

        return $this->idempotency->run($request, 'expenses.'.$action, $this->context->id(), fn () => ApiResponse::success((new ExpenseResource($this->service->transition($expense, $action, $request->validated(), $this->context->get(), $request)))->resolve()));
    }

    public function pay(ExpensePaymentRequest $request, string $id)
    {
        $expense = Expense::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return $this->idempotency->run($request, 'expenses.payment.request', $this->context->id(), fn () => ApiResponse::success($this->service->startPayment($expense, $request->validated(), $this->context->get(), $request), 201));
    }

    public function obligations(Request $request)
    {
        [$items, $meta] = $this->service->unpaid($this->context->get(), $request);

        return ApiResponse::success(ExpenseObligationResource::collection($items)->resolve(), 200, $meta);
    }

    public function obligation(string $id)
    {
        $item = ExpenseObligation::where('company_id', $this->context->id())->with(['expense', 'payee', 'currency', 'paymentSources.expenseObligation'])->whereKey($id)->firstOrFail();

        return ApiResponse::success((new ExpenseObligationResource($item))->resolve());
    }

    public function uploadEvidence(Request $request, string $id)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,webp'], 'evidence_type' => ['nullable', 'string', 'max:32'], 'receipt_reference' => ['nullable', 'string', 'max:180'], 'receipt_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $expense = Expense::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $attachment = $this->attachments->upload($request->file('file'), $expense, $this->context->get(), $request, 'expenses');
        $evidence = ExpenseEvidence::firstOrCreate(['expense_id' => $expense->id, 'attachment_id' => $attachment->id], ['id' => (string) Str::uuid(), 'company_id' => $this->context->id(), 'evidence_type' => $request->input('evidence_type', 'receipt'), 'receipt_reference' => $request->input('receipt_reference'), 'receipt_date' => $request->input('receipt_date'), 'status' => 'complete', 'requirement_status' => 'satisfied', 'notes' => $request->input('notes'), 'uploaded_by' => $request->user()?->id]);
        $expense->update(['evidence_status' => 'complete', 'version' => $expense->version + 1]);

        return ApiResponse::success((new ExpenseEvidenceResource($evidence->load('attachment')))->resolve(), 201);
    }

    public function evidence(string $id)
    {
        $items = ExpenseEvidence::where('company_id', $this->context->id())->where('expense_id', $id)->with('attachment')->latest()->get();

        return ApiResponse::success(ExpenseEvidenceResource::collection($items)->resolve());
    }

    public function downloadEvidence(Request $request, string $id, string $attachmentId)
    {
        $expense = Expense::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();
        $evidence = $expense->evidences()->where('attachment_id', $attachmentId)->with('attachment')->firstOrFail();

        return $this->attachments->download($evidence->attachment, $this->context->get(), $request);
    }
}
