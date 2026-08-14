<?php

namespace App\Services;

use App\Events\ExpenseLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\BusinessTransaction;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\ExpenseApproval;
use App\Models\ExpenseCategory;
use App\Models\ExpenseDuplicateCandidate;
use App\Models\ExpenseLine;
use App\Models\ExpenseObligation;
use App\Models\ExpenseStatusHistory;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\ReferenceCurrency;
use App\Models\TaxCode;
use App\Support\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class ExpenseService
{
    public function __construct(private readonly CashDocumentNumberService $numbers, private readonly PaymentService $payments, private readonly AuditService $audit) {}

    public function lookups(Company $company): array
    {
        return [
            'categories' => ExpenseCategory::where('company_id', $company->id)->where('status', 'active')->with('accountTitle')->orderBy('name')->get(['id', 'code', 'name', 'account_title_id']),
            'accounts' => AccountTitle::where('company_id', $company->id)->where('classification', 'expense')->where('status', 'active')->where('posting_eligible', true)->orderBy('code')->get(['id', 'code', 'name', 'account_subtype', 'locked']),
            'payees' => BusinessPartner::where('company_id', $company->id)->where('status', 'active')->orderBy('display_name')->get(['id', 'code', 'display_name', 'official_name']),
            'currencies' => ReferenceCurrency::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'symbol', 'decimal_precision']),
            'branches' => DB::table('branches')->where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'payment_terms' => PaymentTerm::where('company_id', $company->id)->where('status', 'active')->orderBy('due_days')->get(['id', 'code', 'name', 'term_type', 'due_days', 'end_of_month']),
            'tax_codes' => TaxCode::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'rate', 'basis', 'recoverable', 'withholding']),
            'payment_methods' => PaymentMethod::where('company_id', $company->id)->where('status', 'active')->where('supports_outgoing', true)->orderBy('name')->get(['id', 'code', 'name', 'method_class', 'clearing_behavior', 'requires_external_reference']),
            'cash_accounts' => $this->payments->lookups($company)['cash_accounts'],
        ];
    }

    public function summary(Company $company): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();
        $posted = Expense::where('company_id', $company->id)->whereIn('status', ['approved', 'payment_ready', 'scheduled', 'partially_paid', 'paid', 'closed'])->whereBetween('business_date', [$start, $end]);
        $unpaid = ExpenseObligation::where('company_id', $company->id)->where('remaining_amount', '>', 0);

        return [
            'expenses_this_period' => $posted->count(),
            'period_totals' => $posted->select('currency_id', DB::raw('SUM(total) as amount'))->groupBy('currency_id')->with('currency')->get()->map(fn ($row) => ['currency_id' => $row->currency_id, 'currency' => $row->currency?->code, 'amount' => (string) $row->amount])->values(),
            'unpaid_by_currency' => (clone $unpaid)->select('currency_id', DB::raw('SUM(remaining_amount) as amount'))->groupBy('currency_id')->with('currency')->get()->map(fn ($row) => ['currency_id' => $row->currency_id, 'currency' => $row->currency?->code, 'amount' => (string) $row->amount])->values(),
            'awaiting_approval' => Expense::where('company_id', $company->id)->whereIn('status', ['submitted', 'for_approval'])->count(),
            'missing_evidence' => Expense::where('company_id', $company->id)->where('evidence_status', 'missing')->whereNotIn('status', ['cancelled', 'rejected'])->count(),
            'due_soon' => (clone $unpaid)->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])->count(),
            'overdue' => (clone $unpaid)->where('due_date', '<', now()->toDateString())->count(),
            'payment_ready' => (clone $unpaid)->where('payment_ready', true)->count(),
            'reimbursements_due' => DB::table('reimbursement_obligations')->where('company_id', $company->id)->where('remaining_amount', '>', 0)->count(),
            'recurring_run_rate' => DB::table('recurring_expense_templates')->where('company_id', $company->id)->where('active', true)->sum('amount'),
            'corrections_pending_cash' => DB::table('expense_adjustment_entries')->where('company_id', $company->id)->where('status', 'cash_pending')->count(),
            'recent' => Expense::where('company_id', $company->id)->with(['currency', 'payee'])->latest()->limit(8)->get(),
        ];
    }

    public function listHistory(Company $company, Request $request): array
    {
        $query = Expense::where('company_id', $company->id)->with(['currency', 'payee', 'branch', 'lines.category', 'lines.account', 'obligation']);
        $this->filter($query, $request);
        $paginator = $query->orderByDesc('business_date')->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 25), 100));

        return [$paginator->items(), ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function unpaid(Company $company, Request $request): array
    {
        $query = ExpenseObligation::where('company_id', $company->id)->where('remaining_amount', '>', 0)->with(['expense.currency', 'expense.branch', 'payee', 'currency']);
        $query->when($request->filled('payee_id'), fn ($q) => $q->where('payee_id', $request->string('payee_id')));
        $query->when($request->filled('currency_id'), fn ($q) => $q->where('currency_id', $request->string('currency_id')));
        $query->when($request->filled('due_status'), fn ($q) => $q->where('due_status', $request->string('due_status')));
        $query->when($request->boolean('overdue'), fn ($q) => $q->where('due_status', 'overdue'));
        $query->when($request->filled('q'), fn ($q) => $q->whereHas('expense', fn ($expense) => $expense->where('expense_number', 'like', '%'.$request->string('q').'%')->orWhere('description', 'like', '%'.$request->string('q').'%')));
        $paginator = $query->orderBy('due_date')->paginate(min((int) $request->integer('per_page', 25), 100));

        return [$paginator->items(), ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function attention(Company $company): array
    {
        return [
            'items' => Expense::where('company_id', $company->id)->where(function ($q) {
                $q->whereIn('status', ['submitted', 'for_approval', 'returned'])->orWhere('evidence_status', 'missing')->orWhere('duplicate_status', 'open')->orWhereHas('obligation', fn ($obligation) => $obligation->where('remaining_amount', '>', 0)->where(function ($inner) {
                    $inner->where('due_status', 'overdue')->orWhere('payment_ready', true);
                }));
            })->with(['currency', 'payee', 'obligation'])->orderByDesc('updated_at')->limit(50)->get(),
            'reimbursements_pending' => DB::table('reimbursement_claims')->where('company_id', $company->id)->whereIn('status', ['submitted', 'for_approval', 'payment_ready'])->count(),
            'recurring_due' => DB::table('recurring_expense_templates')->where('company_id', $company->id)->where('active', true)->whereDate('next_run_date', '<=', now()->toDateString())->count(),
            'correction_exceptions' => DB::table('expense_adjustment_entries')->where('company_id', $company->id)->whereIn('status', ['cash_pending', 'failed'])->count(),
            'generated_at' => now()->toISOString(),
        ];
    }

    public function createDraft(array $input, Company $company, Request $request): Expense
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $calculated = $this->calculateInput($input, $company, $request);
            $payee = $this->payee($input['payee_id'] ?? null, $company);
            if (in_array($input['settlement_intent'], ['paid_now', 'pay_later'], true) && ! $payee) {
                throw new RegistryConflictException('A same-company Payee is required for a paid or payable Expense.');
            }
            $businessDate = Carbon::parse($input['business_date']);
            $dueDate = $this->dueDate($businessDate, $input['payment_term_id'] ?? null, $input['due_date'] ?? null, $company);
            $expense = Expense::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'expense_number' => $this->numbers->next($company->id, 'expense'), 'business_date' => $businessDate->toDateString(), 'branch_id' => $input['branch_id'] ?? null, 'payee_id' => $payee?->id, 'payee_name_snapshot' => $payee?->display_name ?? ($input['payee_name'] ?? null), 'external_reference' => $input['external_reference'] ?? null, 'description' => $input['description'], 'currency_id' => $calculated['currency']->id, 'functional_currency_id' => $calculated['functional_currency']->id, 'exchange_rate' => $calculated['exchange_rate'], 'settlement_intent' => $input['settlement_intent'], 'payment_term_id' => $input['payment_term_id'] ?? null, 'due_date' => $dueDate?->toDateString(), 'status' => 'draft', 'approval_status' => ! empty($input['approval_required']) ? 'pending' : 'not_required', 'payment_status' => 'unpaid', 'evidence_status' => ! empty($input['evidence_required']) ? 'missing' : 'not_required', 'duplicate_status' => 'clear', 'approval_required' => (bool) ($input['approval_required'] ?? false), 'evidence_required' => (bool) ($input['evidence_required'] ?? false), 'subtotal' => $calculated['subtotal'], 'taxable_amount' => $calculated['taxable_amount'], 'tax_amount' => $calculated['tax_amount'], 'recoverable_tax_amount' => $calculated['recoverable_tax_amount'], 'nonrecoverable_tax_amount' => $calculated['nonrecoverable_tax_amount'], 'withholding_amount' => $calculated['withholding_amount'], 'total' => $calculated['total'], 'paid_amount' => '0', 'remaining_amount' => $calculated['total'], 'functional_subtotal' => $calculated['functional_subtotal'], 'functional_tax_amount' => $calculated['functional_tax_amount'], 'functional_total' => $calculated['functional_total'], 'functional_paid_amount' => '0', 'functional_remaining_amount' => $calculated['functional_total'], 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id, 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $this->replaceLines($expense, $calculated['lines'], $company);
            $this->duplicates($expense, $company, $request);
            $this->recordHistory($expense, null, 'draft', 'EVT-EXP-001', $company, $request, 'Expense draft created.');
            $this->audit->record($request, 'expense.draft.created', $expense, $company->id, [], ['expense_number' => $expense->expense_number, 'total' => $expense->total], null, 'Expense draft created', 'A new editable Expense Record was created.');
            $this->event('EVT-EXP-001', $expense, $company, $request);

            return $expense->refresh()->load($this->relations());
        });
    }

    public function updateDraft(Expense $expense, array $input, Company $company, Request $request): Expense
    {
        return DB::transaction(function () use ($expense, $input, $company, $request) {
            $locked = $this->locked($expense, $company);
            $this->assertVersion($locked->version, $input['version'] ?? null);
            if (! in_array($locked->status, ['draft', 'returned'], true)) {
                throw new RegistryConflictException('Only Draft or Returned Expenses can be edited.');
            }
            $calculated = $this->calculateInput($input, $company, $request);
            $payee = $this->payee($input['payee_id'] ?? null, $company);
            if (in_array($input['settlement_intent'], ['paid_now', 'pay_later'], true) && ! $payee) {
                throw new RegistryConflictException('A same-company Payee is required for a paid or payable Expense.');
            }
            $businessDate = Carbon::parse($input['business_date']);
            $dueDate = $this->dueDate($businessDate, $input['payment_term_id'] ?? null, $input['due_date'] ?? null, $company);
            $locked->update(['business_date' => $businessDate->toDateString(), 'branch_id' => $input['branch_id'] ?? null, 'payee_id' => $payee?->id, 'payee_name_snapshot' => $payee?->display_name ?? ($input['payee_name'] ?? null), 'external_reference' => $input['external_reference'] ?? null, 'description' => $input['description'], 'currency_id' => $calculated['currency']->id, 'functional_currency_id' => $calculated['functional_currency']->id, 'exchange_rate' => $calculated['exchange_rate'], 'settlement_intent' => $input['settlement_intent'], 'payment_term_id' => $input['payment_term_id'] ?? null, 'due_date' => $dueDate?->toDateString(), 'approval_required' => (bool) ($input['approval_required'] ?? $locked->approval_required), 'evidence_required' => (bool) ($input['evidence_required'] ?? $locked->evidence_required), 'approval_status' => ! empty($input['approval_required']) ? 'pending' : 'not_required', 'evidence_status' => ! empty($input['evidence_required']) ? 'missing' : 'not_required', 'duplicate_status' => 'clear', 'subtotal' => $calculated['subtotal'], 'taxable_amount' => $calculated['taxable_amount'], 'tax_amount' => $calculated['tax_amount'], 'recoverable_tax_amount' => $calculated['recoverable_tax_amount'], 'nonrecoverable_tax_amount' => $calculated['nonrecoverable_tax_amount'], 'withholding_amount' => $calculated['withholding_amount'], 'total' => $calculated['total'], 'remaining_amount' => $calculated['total'], 'functional_subtotal' => $calculated['functional_subtotal'], 'functional_tax_amount' => $calculated['functional_tax_amount'], 'functional_total' => $calculated['functional_total'], 'functional_paid_amount' => '0', 'functional_remaining_amount' => $calculated['functional_total'], 'updated_by' => $request->user()?->id, 'version' => $locked->version + 1]);
            $locked->lines()->delete();
            $this->replaceLines($locked, $calculated['lines'], $company);
            $this->duplicates($locked, $company, $request);
            $this->audit->record($request, 'expense.draft.updated', $locked, $company->id, [], ['version' => $locked->version], null, 'Expense draft updated', 'Expense draft classification and totals were recalculated.');

            return $locked->refresh()->load($this->relations());
        });
    }

    public function calculate(array $input, Company $company, Request $request): array
    {
        $calculated = $this->calculateInput($input, $company, $request);

        return ['subtotal' => $calculated['subtotal'], 'taxable_amount' => $calculated['taxable_amount'], 'tax_amount' => $calculated['tax_amount'], 'recoverable_tax_amount' => $calculated['recoverable_tax_amount'], 'nonrecoverable_tax_amount' => $calculated['nonrecoverable_tax_amount'], 'total' => $calculated['total'], 'functional_currency' => ['id' => $calculated['functional_currency']->id, 'code' => $calculated['functional_currency']->code], 'exchange_rate' => $calculated['exchange_rate'], 'functional_total' => $calculated['functional_total'], 'currency' => ['id' => $calculated['currency']->id, 'code' => $calculated['currency']->code], 'lines' => collect($calculated['lines'])->map(fn ($line) => collect($line)->except(['category', 'account', 'tax_code'])->all())->values()];
    }

    public function transition(Expense $expense, string $action, array $input, Company $company, Request $request): Expense
    {
        return DB::transaction(function () use ($expense, $action, $input, $company, $request) {
            $locked = $this->locked($expense, $company);
            $this->assertVersion($locked->version, $input['version'] ?? null);
            $from = $locked->status;
            $reason = $input['reason'] ?? null;
            if ($action === 'submit') {
                if (! in_array($from, ['draft', 'returned'], true)) {
                    throw new RegistryConflictException('Only Draft or Returned Expenses can be submitted.');
                }
                $this->validateReady($locked, $company, $request);
                if ($locked->approval_required) {
                    $locked->update(['status' => 'for_approval', 'approval_status' => 'pending', 'submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'version' => $locked->version + 1]);
                    ExpenseApproval::create(['id' => (string) Str::uuid(), 'expense_id' => $locked->id, 'company_id' => $company->id, 'status' => 'pending', 'action' => 'submitted', 'submitted_version' => $locked->version, 'actor_id' => $request->user()?->id, 'authority_context' => ['amount' => (string) $locked->total], 'correlation_id' => $request->attributes->get('correlation_id')]);
                } else {
                    $locked->update(['status' => 'approved', 'approval_status' => 'approved', 'submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $locked->version + 1]);
                }
                $event = 'EVT-EXP-002';
            } elseif ($action === 'review') {
                if (! in_array($from, ['submitted', 'for_approval'], true)) {
                    throw new RegistryConflictException('Only submitted Expenses can be reviewed.');
                }
                $locked->update(['status' => 'for_approval', 'version' => $locked->version + 1]);
                $event = 'EVT-EXP-002';
            } elseif ($action === 'approve') {
                if ($from !== 'for_approval' || ! $locked->approval_required) {
                    throw new RegistryConflictException('Only Expenses in an approval route can be approved.');
                }
                if ((int) $locked->created_by === (int) $request->user()?->id) {
                    throw new RegistryConflictException('The Expense creator cannot approve the same Expense.');
                }
                $approval = $locked->approvals()->where('status', 'pending')->latest()->lockForUpdate()->firstOrFail();
                $approval->update(['status' => 'approved', 'action' => 'approved', 'actor_id' => $request->user()?->id, 'acted_at' => now(), 'reason' => $reason]);
                $locked->update(['status' => 'approved', 'approval_status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $locked->version + 1]);
                $event = 'EVT-EXP-003';
            } elseif ($action === 'reject') {
                if ($from !== 'for_approval') {
                    throw new RegistryConflictException('Only Expenses in an approval route can be rejected.');
                }
                $this->requireReason($reason, 'A rejection reason is required.');
                $locked->approvals()->where('status', 'pending')->latest()->first()?->update(['status' => 'rejected', 'action' => 'rejected', 'actor_id' => $request->user()?->id, 'acted_at' => now(), 'reason' => $reason]);
                $locked->update(['status' => 'rejected', 'approval_status' => 'rejected', 'version' => $locked->version + 1]);
                $event = 'EVT-EXP-004';
            } elseif ($action === 'return') {
                if (! in_array($from, ['submitted', 'for_approval'], true)) {
                    throw new RegistryConflictException('Only submitted Expenses can be returned.');
                }
                $this->requireReason($reason, 'A return reason is required.');
                $locked->approvals()->where('status', 'pending')->latest()->first()?->update(['status' => 'returned', 'action' => 'returned', 'actor_id' => $request->user()?->id, 'acted_at' => now(), 'reason' => $reason]);
                $locked->update(['status' => 'returned', 'approval_status' => 'returned', 'version' => $locked->version + 1]);
                $event = 'EVT-EXP-005';
            } elseif ($action === 'post') {
                if ($from !== 'approved') {
                    throw new RegistryConflictException('Only approved Expenses can be posted.');
                }
                if ($locked->settlement_intent === 'reimbursement') {
                    throw new RegistryConflictException('Reimbursement Expenses must be posted through an approved Reimbursement Claim.');
                }
                $this->validateReady($locked, $company, $request);
                $this->postAccounting($locked, $company, $request);
                $locked->update(['status' => 'payment_ready', 'payment_status' => 'unpaid', 'remaining_amount' => $locked->total, 'posted_by' => $request->user()?->id, 'posted_at' => now(), 'version' => $locked->version + 1]);
                $this->createObligation($locked, $company, $request);
                $event = 'EVT-EXP-006';
            } elseif ($action === 'cancel') {
                if (! in_array($from, ['draft', 'returned', 'approved'], true) || $locked->accounting_transaction_id) {
                    throw new RegistryConflictException('This Expense is not eligible for cancellation.');
                }
                $this->requireReason($reason, 'A cancellation reason is required.');
                $locked->update(['status' => 'cancelled', 'cancellation_reason' => $reason, 'version' => $locked->version + 1]);
                $event = 'EVT-EXP-015';
            } elseif ($action === 'close') {
                if ($from !== 'paid') {
                    throw new RegistryConflictException('Only fully settled Expenses can be closed.');
                }
                $locked->update(['status' => 'closed', 'version' => $locked->version + 1]);
                $event = 'EVT-EXP-018';
            } else {
                throw new RegistryConflictException('The Expense action is not supported.');
            }
            $this->recordHistory($locked, $from, $locked->status, $event, $company, $request, $reason);
            $this->audit->record($request, 'expense.'.$action, $locked, $company->id, ['status' => $from], ['status' => $locked->status, 'approval_status' => $locked->approval_status], $reason, 'Expense '.ucfirst($action), 'A governed Expense lifecycle action was recorded.');
            $this->event($event, $locked, $company, $request);

            return $locked->refresh()->load($this->relations());
        });
    }

    public function startPayment(Expense $expense, array $input, Company $company, Request $request)
    {
        $locked = $this->locked($expense, $company)->load('obligation');
        if (! $locked->obligation || ! $locked->obligation->payment_ready || bccomp((string) $locked->obligation->remaining_amount, '0', 6) <= 0) {
            throw new RegistryConflictException('This Expense does not have a payment-ready remaining obligation.');
        }
        if (! in_array($locked->status, ['payment_ready', 'partially_paid', 'scheduled'], true)) {
            throw new RegistryConflictException('Only payment-ready Expenses can be handed to MDS-500.');
        }
        $payment = $this->payments->createExpensePayment($input, $locked->obligation, $company, $request);
        $locked->update(['payment_status' => 'pending', 'version' => $locked->version + 1]);
        $this->recordHistory($locked, $locked->status, $locked->status, 'EVT-EXP-009', $company, $request, 'Expense payment handed to MDS-500.');
        $this->audit->record($request, 'expense.payment.requested', $locked, $company->id, [], ['payment_id' => $payment->id], null, 'Expense payment requested', 'The Expense Obligation was handed to MDS-500.');
        $this->event('EVT-EXP-009', $locked, $company, $request);

        return $payment;
    }

    public function postReimbursement(Expense $expense, Company $company, Request $request): Expense
    {
        return DB::transaction(function () use ($expense, $company, $request) {
            $locked = $this->locked($expense, $company);
            if (! in_array($locked->status, ['draft', 'submitted', 'for_approval', 'approved', 'returned'], true)) {
                if ($locked->accounting_transaction_id && in_array($locked->status, ['payment_ready', 'partially_paid', 'paid', 'closed'], true)) {
                    return $locked;
                }
                throw new RegistryConflictException('The reimbursement Expense is not eligible for posting.');
            }
            $this->validateReady($locked, $company, $request);
            $this->postAccounting($locked, $company, $request);
            $locked->update(['status' => 'payment_ready', 'payment_status' => 'unpaid', 'remaining_amount' => $locked->total, 'posted_by' => $request->user()?->id, 'posted_at' => now(), 'version' => $locked->version + 1]);
            $this->recordHistory($locked, $locked->getOriginal('status'), 'payment_ready', 'EVT-EXP-006', $company, $request, 'Reimbursement Expense posted through its approved Claim.');
            $this->event('EVT-EXP-006', $locked, $company, $request);

            return $locked->refresh()->load($this->relations());
        });
    }

    public function show(string $id, Company $company): Expense
    {
        return Expense::where('company_id', $company->id)->whereKey($id)->with($this->relations())->firstOrFail();
    }

    private function calculateInput(array $input, Company $company, Request $request): array
    {
        $currency = ReferenceCurrency::where('company_id', $company->id)->whereKey($input['currency_id'])->where('status', 'active')->first();
        if (! $currency) {
            throw new RegistryConflictException('The Currency must be active and belong to the current company.');
        }
        $lines = [];
        $subtotal = $taxable = $tax = $recoverable = $nonrecoverable = $withholding = $total = '0';
        foreach (array_values($input['lines']) as $index => $lineInput) {
            $category = ExpenseCategory::where('company_id', $company->id)->whereKey($lineInput['expense_category_id'])->where('status', 'active')->with('accountTitle')->first();
            if (! $category) {
                throw new RegistryConflictException('Every Expense line must use an active same-company Expense Category.');
            }
            $accountId = $lineInput['expense_account_title_id'] ?? $category->account_title_id;
            if (! empty($lineInput['expense_account_title_id']) && ! $request->user()?->hasPermission('expenses.account.override', $company->id)) {
                $accountId = $category->account_title_id;
            }
            $account = AccountTitle::where('company_id', $company->id)->whereKey($accountId)->where('classification', 'expense')->where('status', 'active')->where('posting_eligible', true)->first();
            if (! $account) {
                throw new RegistryConflictException('Each Expense line must resolve to an active posting Expense Account.');
            }
            $branchId = $lineInput['branch_id'] ?? ($input['branch_id'] ?? null);
            if ($branchId && ! DB::table('branches')->where('company_id', $company->id)->where('id', $branchId)->where('status', 'active')->exists()) {
                throw new RegistryConflictException('The Expense line Branch is outside the current company or inactive.');
            }
            $quantity = $this->decimal($lineInput['quantity'], false);
            $unit = $this->decimal($lineInput['unit_amount']);
            $base = bcmul($quantity, $unit, 6);
            $taxCode = null;
            if (! empty($lineInput['tax_code_id'])) {
                $taxCode = TaxCode::where('company_id', $company->id)->whereKey($lineInput['tax_code_id'])->where('status', 'active')->first();
                if (! $taxCode) {
                    throw new RegistryConflictException('The Tax Code must be active and belong to the current company.');
                }
            }
            [$taxableAmount, $taxAmount, $lineTotal] = $this->tax($base, $taxCode);
            $recoverableAmount = $taxCode?->recoverable ? $taxAmount : '0';
            $nonrecoverableAmount = $taxCode?->recoverable ? '0' : $taxAmount;
            $line = ['line_number' => $index + 1, 'expense_category_id' => $category->id, 'expense_account_title_id' => $account->id, 'description' => $lineInput['description'], 'quantity' => $quantity, 'unit_amount' => $unit, 'line_amount' => $base, 'tax_code_id' => $taxCode?->id, 'tax_basis' => $taxCode?->basis, 'taxable_amount' => $taxableAmount, 'tax_amount' => $taxAmount, 'recoverable_tax_amount' => $recoverableAmount, 'nonrecoverable_tax_amount' => $nonrecoverableAmount, 'withholding_amount' => '0', 'line_total' => $lineTotal, 'branch_id' => $branchId, 'allocations' => $lineInput['allocations'] ?? []];
            $lines[] = $line;
            $subtotal = bcadd($subtotal, $base, 6);
            $taxable = bcadd($taxable, $taxableAmount, 6);
            $tax = bcadd($tax, $taxAmount, 6);
            $recoverable = bcadd($recoverable, $recoverableAmount, 6);
            $nonrecoverable = bcadd($nonrecoverable, $nonrecoverableAmount, 6);
            $withholding = bcadd($withholding, '0', 6);
            $total = bcadd($total, $lineTotal, 6);
        }

        $functionalCurrency = ReferenceCurrency::where('company_id', $company->id)->whereKey($company->default_currency_id ?: $currency->id)->where('status', 'active')->first() ?: $currency;
        $exchangeRate = $this->decimal($input['exchange_rate'] ?? '1');

        return compact('currency', 'lines', 'subtotal', 'taxable', 'tax', 'recoverable', 'nonrecoverable', 'withholding', 'total') + ['functional_currency' => $functionalCurrency, 'exchange_rate' => $exchangeRate, 'functional_subtotal' => bcmul($subtotal, $exchangeRate, 6), 'functional_tax_amount' => bcmul($tax, $exchangeRate, 6), 'functional_total' => bcmul($total, $exchangeRate, 6), 'taxable_amount' => $taxable, 'tax_amount' => $tax, 'recoverable_tax_amount' => $recoverable, 'nonrecoverable_tax_amount' => $nonrecoverable, 'withholding_amount' => $withholding];
    }

    private function replaceLines(Expense $expense, array $lines, Company $company): void
    {
        foreach ($lines as $line) {
            $allocations = $line['allocations'] ?? [];
            unset($line['allocations']);
            $record = ExpenseLine::create(['id' => (string) Str::uuid(), 'expense_id' => $expense->id, 'company_id' => $company->id] + $line + ['version' => 1]);
            if (! $allocations) {
                $allocations = [['expense_account_title_id' => $record->expense_account_title_id, 'expense_category_id' => $record->expense_category_id, 'branch_id' => $record->branch_id, 'allocation_percent' => '100']];
            }
            $percent = '0';
            foreach ($allocations as $allocation) {
                $percent = bcadd($percent, (string) $allocation['allocation_percent'], 6);
                $accountId = $allocation['expense_account_title_id'] ?? $record->expense_account_title_id;
                $account = AccountTitle::where('company_id', $company->id)->whereKey($accountId)->where('classification', 'expense')->where('status', 'active')->where('posting_eligible', true)->first();
                if (! $account) {
                    throw new RegistryConflictException('Every Expense allocation must use an active posting Expense Account.');
                }
                $amount = bcdiv(bcmul((string) $record->line_total, (string) $allocation['allocation_percent'], 12), '100', 6);
                ExpenseAllocation::create(['id' => (string) Str::uuid(), 'expense_id' => $expense->id, 'expense_line_id' => $record->id, 'company_id' => $company->id, 'expense_account_title_id' => $account->id, 'expense_category_id' => $allocation['expense_category_id'] ?? $record->expense_category_id, 'branch_id' => $allocation['branch_id'] ?? $record->branch_id, 'allocation_percent' => $allocation['allocation_percent'], 'amount' => $amount, 'status' => 'active']);
            }
            if (bccomp($percent, '100', 6) !== 0) {
                throw new RegistryConflictException('Expense line allocations must total exactly 100 percent.');
            }
        }
    }

    private function validateReady(Expense $expense, Company $company, Request $request): void
    {
        if ($expense->settlement_intent !== 'reimbursement' && (! $expense->payee_id || ! $this->payee($expense->payee_id, $company))) {
            throw new RegistryConflictException('A valid same-company Payee is required before submission.');
        }
        if (! $expense->lines()->exists()) {
            throw new RegistryConflictException('At least one Expense line is required.');
        }
        if ($expense->evidence_required && ! $expense->evidences()->where('requirement_status', 'satisfied')->exists()) {
            throw new RegistryConflictException('Required Expense evidence is missing.');
        }
        if (in_array($expense->duplicate_status, ['open', 'exact', 'probable'], true)) {
            $reason = $expense->duplicate_override_reason;
            if (! $reason || ! $request->user()?->hasPermission('expenses.duplicate.override', $company->id)) {
                throw new RegistryConflictException('A possible duplicate Expense requires resolution or an authorized override reason.');
            }
        }
        if (! $expense->accounting_transaction_id && $expense->status === 'approved') {
            $this->calculateInput(['currency_id' => $expense->currency_id, 'branch_id' => $expense->branch_id, 'lines' => $expense->lines->map(fn ($line) => ['expense_category_id' => $line->expense_category_id, 'expense_account_title_id' => $line->expense_account_title_id, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_amount' => $line->unit_amount, 'tax_code_id' => $line->tax_code_id, 'branch_id' => $line->branch_id])->all()], $company, $request);
        }
    }

    private function postAccounting(Expense $expense, Company $company, Request $request): void
    {
        if ($expense->accounting_transaction_id) {
            return;
        }
        $liability = AccountTitle::where('company_id', $company->id)->where('classification', 'liability')->where('status', 'active')->where('posting_eligible', true)->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%payable%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%payable%'])->orWhereRaw('LOWER(name) LIKE ?', ['%accrued%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%accrued%']))->first();
        if (! $liability) {
            throw new RegistryConflictException('An active posting Payable or Accrued Liability Account Title is required before Expense posting.');
        }
        $taxAccount = null;
        if (bccomp((string) $expense->recoverable_tax_amount, '0', 6) > 0) {
            $taxAccount = AccountTitle::where('company_id', $company->id)->where('status', 'active')->where('posting_eligible', true)->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%tax%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%tax%']))->first();
        }
        if (bccomp((string) $expense->recoverable_tax_amount, '0', 6) > 0 && ! $taxAccount) {
            throw new RegistryConflictException('An active recoverable tax Account Title is required before Expense posting.');
        }
        $business = BusinessTransaction::firstOrCreate(['company_id' => $company->id, 'idempotency_key' => 'expense:'.$expense->id.':posting'], ['id' => (string) Str::uuid(), 'transaction_type' => 'expense', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $expense->business_date, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $transaction = $business->accountingTransaction()->firstOrCreate([], ['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'expense', 'status' => 'posted', 'business_date' => $expense->business_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        if (! $transaction->lines()->exists()) {
            foreach ($expense->lines as $line) {
                $debit = bcadd((string) $line->taxable_amount, (string) $line->nonrecoverable_tax_amount, 6);
                if (bccomp($debit, '0', 6) > 0) {
                    $transaction->lines()->create(['id' => (string) Str::uuid(), 'account_title_id' => $line->expense_account_title_id, 'debit' => $debit, 'credit' => '0', 'currency_code' => $expense->currency->code, 'description' => $line->description]);
                }
                if (bccomp((string) $line->recoverable_tax_amount, '0', 6) > 0) {
                    $transaction->lines()->create(['id' => (string) Str::uuid(), 'account_title_id' => $taxAccount->id, 'debit' => $line->recoverable_tax_amount, 'credit' => '0', 'currency_code' => $expense->currency->code, 'description' => 'Recoverable tax for '.$expense->expense_number]);
                }
            }
            $transaction->lines()->create(['id' => (string) Str::uuid(), 'account_title_id' => $liability->id, 'debit' => '0', 'credit' => $expense->total, 'currency_code' => $expense->currency->code, 'description' => 'Expense obligation for '.$expense->expense_number]);
        }
        $expense->update(['business_transaction_id' => $business->id, 'accounting_transaction_id' => $transaction->id]);
    }

    private function createObligation(Expense $expense, Company $company, Request $request): void
    {
        if (! in_array($expense->settlement_intent, ['paid_now', 'pay_later'], true) || ! $expense->payee_id) {
            return;
        }
        ExpenseObligation::firstOrCreate(['company_id' => $company->id, 'expense_id' => $expense->id], ['id' => (string) Str::uuid(), 'payee_id' => $expense->payee_id, 'currency_id' => $expense->currency_id, 'original_amount' => $expense->total, 'paid_amount' => '0', 'credited_amount' => '0', 'refunded_amount' => '0', 'adjusted_amount' => '0', 'remaining_amount' => $expense->total, 'due_date' => $expense->due_date ?? $expense->business_date, 'due_status' => $this->dueStatus($expense->due_date ?? $expense->business_date), 'payment_ready' => true, 'settlement_status' => 'unpaid', 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function duplicates(Expense $expense, Company $company, Request $request): void
    {
        $query = Expense::where('company_id', $company->id)->where('id', '!=', $expense->id)->whereNotIn('status', ['cancelled', 'rejected']);
        $query->where('currency_id', $expense->currency_id)->where('total', $expense->total)->whereDate('business_date', $expense->business_date);
        if ($expense->payee_id) {
            $query->where('payee_id', $expense->payee_id);
        }
        $candidates = $query->limit(10)->get();
        foreach ($candidates as $candidate) {
            $type = $expense->external_reference && $candidate->external_reference && $expense->external_reference === $candidate->external_reference ? 'exact' : 'probable';
            ExpenseDuplicateCandidate::firstOrCreate(['expense_id' => $expense->id, 'candidate_expense_id' => $candidate->id], ['id' => (string) Str::uuid(), 'company_id' => $company->id, 'risk_type' => $type, 'score' => $type === 'exact' ? 100 : 75, 'status' => 'open']);
            $expense->duplicate_status = $type === 'exact' ? 'exact' : 'open';
        }
        if ($expense->duplicate_status !== 'clear' && $request->user()?->hasPermission('expenses.duplicate.override', $company->id) && trim((string) $request->input('duplicate_override_reason'))) {
            $expense->duplicate_override_reason = $request->input('duplicate_override_reason');
        }
        $expense->save();
    }

    private function filter($query, Request $request): void
    {
        $query->when($request->filled('q'), fn ($q) => $q->where(fn ($inner) => $inner->where('expense_number', 'like', '%'.$request->string('q').'%')->orWhere('description', 'like', '%'.$request->string('q').'%')->orWhere('payee_name_snapshot', 'like', '%'.$request->string('q').'%')));
        foreach (['status', 'payment_status', 'approval_status', 'evidence_status', 'duplicate_status', 'currency_id', 'branch_id', 'payee_id'] as $field) {
            $query->when($request->filled($field), fn ($q) => $q->where($field, $request->string($field)));
        }
        $query->when($request->filled('from'), fn ($q) => $q->whereDate('business_date', '>=', $request->date('from')));
        $query->when($request->filled('to'), fn ($q) => $q->whereDate('business_date', '<=', $request->date('to')));
    }

    private function locked(Expense $expense, Company $company): Expense
    {
        return Expense::where('company_id', $company->id)->whereKey($expense->id)->lockForUpdate()->with($this->relations())->firstOrFail();
    }

    private function relations(): array
    {
        return ['currency', 'payee', 'branch', 'paymentTerm', 'lines.category', 'lines.account', 'lines.taxCode', 'allocations', 'approvals', 'statusHistory', 'evidences.attachment', 'duplicateCandidates.candidate', 'obligation.payee', 'obligation.currency', 'reimbursementClaim', 'recurringTemplate', 'recurringOccurrence', 'adjustments.currency'];
    }

    private function payee(?string $id, Company $company): ?BusinessPartner
    {
        return $id ? BusinessPartner::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->first() : null;
    }

    private function dueDate(Carbon $businessDate, ?string $termId, ?string $manual, Company $company): ?Carbon
    {
        if ($manual) {
            return Carbon::parse($manual);
        }
        if (! $termId) {
            return $businessDate;
        }
        $term = PaymentTerm::where('company_id', $company->id)->whereKey($termId)->where('status', 'active')->first();
        if (! $term) {
            throw new RegistryConflictException('The Payment Term must be active and belong to the current company.');
        }
        $date = $businessDate->copy()->addDays((int) $term->due_days);

        return $term->end_of_month ? $date->endOfMonth() : $date;
    }

    private function dueStatus(string|Carbon $date): string
    {
        $value = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $value->isPast() && ! $value->isToday() ? 'overdue' : ($value->isToday() ? 'due_today' : 'not_due');
    }

    private function tax(string $base, ?TaxCode $taxCode): array
    {
        if (! $taxCode || bccomp((string) $taxCode->rate, '0', 6) === 0) {
            return [$base, '0', $base];
        }
        $rate = (string) $taxCode->rate;
        if ($taxCode->basis === 'inclusive') {
            $tax = bcdiv(bcmul($base, $rate, 12), bcadd('100', $rate, 12), 6);

            return [bcsub($base, $tax, 6), $tax, $base];
        }
        $tax = bcdiv(bcmul($base, $rate, 12), '100', 6);

        return [$base, $tax, bcadd($base, $tax, 6)];
    }

    private function decimal(mixed $value, bool $allowZero = true): string
    {
        if (! is_numeric($value) || (! $allowZero && bccomp((string) $value, '0', 6) <= 0) || ($allowZero && bccomp((string) $value, '0', 6) < 0)) {
            throw new RegistryConflictException('Expense amounts and quantities must be valid non-negative decimals.');
        }

        return bcadd((string) $value, '0', 6);
    }

    private function assertVersion(int $actual, ?int $expected): void
    {
        if ($expected !== null && $actual !== $expected) {
            throw new RegistryConflictException('The Expense was changed by another user. Refresh before continuing.', ['conflict' => true, 'current_version' => $actual]);
        }
    }

    private function requireReason(?string $reason, string $message): void
    {
        if (! trim((string) $reason)) {
            throw new RegistryConflictException($message);
        }
    }

    private function recordHistory(Expense $expense, ?string $from, string $to, string $event, Company $company, Request $request, ?string $reason): void
    {
        ExpenseStatusHistory::create(['id' => (string) Str::uuid(), 'expense_id' => $expense->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => $to, 'approval_status' => $expense->approval_status, 'payment_status' => $expense->payment_status, 'event_code' => $event, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $expense->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function event(string $event, Expense $expense, Company $company, Request $request): void
    {
        Event::dispatch(new ExpenseLifecycleEvent($event, $company->id, $expense->id, $request->user()?->id, $request->attributes->get('correlation_id')));
    }
}
