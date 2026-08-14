<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\ExpensePaymentRequest;
use App\Models\Expense;
use App\Models\ExpenseAdjustmentEntry;
use App\Models\ExpenseImportBatch;
use App\Models\RecurringExpenseTemplate;
use App\Models\ReimbursementClaim;
use App\Services\ExpenseCompletionService;
use App\Support\ApiResponse;
use App\Support\CompanyContext;
use Illuminate\Http\Request;

class ExpenseCompletionController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly ExpenseCompletionService $service) {}

    public function claims(Request $request)
    {
        [$items, $meta] = $this->service->claims($this->context->get(), $request);

        return ApiResponse::success($items, 200, $meta);
    }

    public function storeClaim(Request $request)
    {
        $data = $request->validate(['claimant_user_id' => ['nullable', 'integer'], 'claimant_business_partner_id' => ['required', 'uuid'], 'expense_ids' => ['required', 'array', 'min:1', 'max:100'], 'expense_ids.*' => ['required', 'uuid'], 'business_purpose' => ['required', 'string', 'max:4000'], 'due_date' => ['nullable', 'date'], 'approval_required' => ['nullable', 'boolean'], 'evidence_required' => ['nullable', 'boolean']]);

        return ApiResponse::success($this->service->createClaim($data, $this->context->get(), $request), 201);
    }

    public function claim(string $id)
    {
        return ApiResponse::success($this->service->showClaim($id, $this->context->get()));
    }

    public function claimAction(Request $request, string $id)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000'], 'version' => ['nullable', 'integer', 'min:1']]);
        $claim = ReimbursementClaim::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return ApiResponse::success($this->service->actionClaim($claim, (string) $request->route('action'), $data, $this->context->get(), $request));
    }

    public function payClaim(ExpensePaymentRequest $request, string $id)
    {
        $claim = ReimbursementClaim::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return ApiResponse::success($this->service->payClaim($claim, $request->validated(), $this->context->get(), $request), 201);
    }

    public function templates()
    {
        return ApiResponse::success($this->service->templates($this->context->get()));
    }

    public function storeTemplate(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:180'], 'currency_id' => ['required', 'uuid'], 'payee_id' => ['nullable', 'uuid'], 'expense_category_id' => ['required', 'uuid'], 'expense_account_title_id' => ['required', 'uuid'], 'settlement_intent' => ['nullable', 'in:paid_now,pay_later,reimbursement'], 'frequency' => ['required', 'in:monthly,quarterly,annual'], 'next_run_date' => ['required', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:next_run_date'], 'amount' => ['required', 'numeric', 'gt:0'], 'description' => ['required', 'string', 'max:4000'], 'line_defaults' => ['nullable', 'array']]);

        return ApiResponse::success($this->service->createTemplate($data, $this->context->get(), $request), 201);
    }

    public function generateTemplate(Request $request, string $id)
    {
        $template = RecurringExpenseTemplate::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return ApiResponse::success($this->service->generate($template, $this->context->get(), $request), 201);
    }

    public function toggleTemplate(Request $request, string $id)
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $template = RecurringExpenseTemplate::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return ApiResponse::success($this->service->toggleTemplate($template, (bool) $data['active'], $this->context->get(), $request));
    }

    public function copy(Request $request, string $id)
    {
        $data = $request->validate(['business_date' => ['nullable', 'date'], 'branch_id' => ['nullable', 'uuid'], 'payee_id' => ['nullable', 'uuid'], 'external_reference' => ['nullable', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:4000'], 'currency_id' => ['nullable', 'uuid'], 'settlement_intent' => ['nullable', 'in:paid_now,pay_later,reimbursement'], 'payment_term_id' => ['nullable', 'uuid']]);
        $expense = Expense::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return ApiResponse::success($this->service->copy($expense, $data, $this->context->get(), $request), 201);
    }

    public function correction(Request $request, string $id)
    {
        $data = $request->validate(['adjustment_type' => ['required', 'in:adjustment,credit,refund,reversal'], 'amount' => ['nullable', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:4000'], 'effective_date' => ['nullable', 'date']]);
        $expense = Expense::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return ApiResponse::success($this->service->correction($expense, $data, $this->context->get(), $request), 201);
    }

    public function linkRefund(Request $request, string $id)
    {
        $data = $request->validate(['cash_movement_id' => ['required', 'uuid']]);
        $entry = ExpenseAdjustmentEntry::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return ApiResponse::success($this->service->linkRefund($entry, $data['cash_movement_id'], $this->context->get(), $request));
    }

    public function corrections(Request $request)
    {
        [$items, $meta] = $this->service->corrections($this->context->get(), $request);

        return ApiResponse::success($items, 200, $meta);
    }

    public function report(Request $request, string $report)
    {
        return ApiResponse::success($this->service->report($report, $this->context->get(), $request));
    }

    public function previewImport(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        return ApiResponse::success($this->service->previewImport($request->file('file'), $this->context->get(), $request), 201);
    }

    public function applyImport(Request $request, string $id)
    {
        $batch = ExpenseImportBatch::where('company_id', $this->context->id())->whereKey($id)->firstOrFail();

        return ApiResponse::success($this->service->applyImport($batch, $this->context->get(), $request));
    }
}
