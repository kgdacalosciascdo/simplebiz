<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccounts\EvidenceRequest;
use App\Http\Requests\CashAccounts\ReasonRequest;
use App\Http\Resources\CashAccounts\AttachmentResource;
use App\Http\Resources\CashAccounts\StatementImportResource;
use App\Http\Resources\CashAccounts\StatementLineResource;
use App\Models\StatementImportBatch;
use App\Services\StatementImportService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;

class StatementImportController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly StatementImportService $service, private readonly IdempotencyService $idempotency) {}

    public function index(Request $request)
    {
        $query = StatementImportBatch::where('company_id', $this->context->id())->with('account.currency')->orderByDesc('created_at');
        if ($request->filled('cash_account_id')) {
            $query->where('cash_account_id', $request->string('cash_account_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return StatementImportResource::collection($query->paginate(min((int) $request->integer('per_page', 20), 100)));
    }

    public function show(string $id)
    {
        return ApiResponse::success(new StatementImportResource($this->batch($id)));
    }

    public function store(Request $request)
    {
        $input = $request->validate(['cash_account_id' => ['required', 'uuid'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date'], 'currency_code' => ['nullable', 'string', 'size:3'], 'provider_reference' => ['nullable', 'string', 'max:160'], 'opening_statement_balance' => ['nullable', 'numeric'], 'closing_statement_balance' => ['nullable', 'numeric']]);

        return $this->idempotency->run($request, 'cash-accounts.statements.import', $this->context->id(), fn () => ApiResponse::success(new StatementImportResource($this->service->createBatch($input, $this->context->get(), $request)), 201));
    }

    public function uploadEvidence(EvidenceRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.statements.evidence', $this->context->id(), fn () => ApiResponse::success(new StatementImportResource($this->service->uploadEvidence($this->batch($id), $request->file('file'), $this->context->get(), $request))));
    }

    public function addLine(Request $request, string $id)
    {
        $input = $request->validate(['source_row_number' => ['nullable', 'integer', 'min:1'], 'external_line_id' => ['nullable', 'string', 'max:160'], 'transaction_date' => ['required', 'date'], 'value_date' => ['nullable', 'date'], 'posting_date' => ['nullable', 'date'], 'description' => ['required', 'string', 'max:2000'], 'reference' => ['nullable', 'string', 'max:180'], 'counterparty_name' => ['nullable', 'string', 'max:180'], 'external_account_reference' => ['nullable', 'string', 'max:180'], 'debit_amount' => ['nullable', 'numeric', 'min:0'], 'credit_amount' => ['nullable', 'numeric', 'min:0'], 'currency_code' => ['nullable', 'string', 'size:3'], 'running_balance' => ['nullable', 'numeric'], 'check_reference' => ['nullable', 'string', 'max:120'], 'provider_transaction_type' => ['nullable', 'string', 'max:80'], 'normalized_transaction_type' => ['nullable', 'string', 'max:80']]);

        return $this->idempotency->run($request, 'cash-accounts.statements.lines.create', $this->context->id(), fn () => ApiResponse::success(new StatementLineResource($this->service->addLine($this->batch($id), $input, $this->context->get(), $request)), 201));
    }

    public function validateBatch(Request $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.statements.validate', $this->context->id(), fn () => ApiResponse::success(new StatementImportResource($this->service->validateBatch($this->batch($id), $this->context->get(), $request))));
    }

    public function cancel(ReasonRequest $request, string $id)
    {
        return $this->idempotency->run($request, 'cash-accounts.statements.cancel', $this->context->id(), fn () => ApiResponse::success(new StatementImportResource($this->service->cancel($this->batch($id), $request->validated('reason'), $this->context->get(), $request))));
    }

    public function evidence(string $id)
    {
        return AttachmentResource::collection($this->batch($id)->attachments()->orderByDesc('created_at')->paginate(50));
    }

    private function batch(string $id): StatementImportBatch
    {
        return StatementImportBatch::where('company_id', $this->context->id())->whereKey($id)->with(['account.currency', 'lines', 'attachments'])->firstOrFail();
    }
}
