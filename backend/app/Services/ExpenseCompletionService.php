<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\AccountingTransaction;
use App\Models\BusinessPartner;
use App\Models\BusinessTransaction;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseAdjustmentEntry;
use App\Models\ExpenseImportBatch;
use App\Models\ExpenseImportRow;
use App\Models\ExpenseObligation;
use App\Models\PaymentAllocation;
use App\Models\RecurringExpenseOccurrence;
use App\Models\RecurringExpenseTemplate;
use App\Models\ReferenceCurrency;
use App\Models\ReimbursementApproval;
use App\Models\ReimbursementClaim;
use App\Models\ReimbursementClaimExpense;
use App\Models\ReimbursementObligation;
use App\Models\ReimbursementStatusHistory;
use App\Models\User;
use App\Support\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ExpenseCompletionService
{
    public function __construct(private readonly CashDocumentNumberService $numbers, private readonly ExpenseService $expenses, private readonly PaymentService $payments, private readonly AuditService $audit) {}

    public function claims(Company $company, Request $request): array
    {
        $query = ReimbursementClaim::where('company_id', $company->id)->with(['claimant', 'claimantBusinessPartner', 'currency', 'obligation'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('claimant_user_id'), fn ($q) => $q->where('claimant_user_id', $request->string('claimant_user_id')))->orderByDesc('created_at');
        $paginator = $query->paginate(min((int) $request->integer('per_page', 25), 100));

        return [$paginator->items(), ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function createClaim(array $input, Company $company, Request $request): ReimbursementClaim
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $claimantId = (int) ($input['claimant_user_id'] ?? $request->user()?->id);
            $claimant = User::whereKey($claimantId)->first();
            $claimantIsMember = $claimant && DB::table('company_user')->where('company_id', $company->id)->where('user_id', $claimant->id)->where('status', 'active')->exists();
            $payee = BusinessPartner::where('company_id', $company->id)->whereKey($input['claimant_business_partner_id'] ?? null)->where('status', 'active')->first();
            if (! $claimant || ! $claimantIsMember) {
                throw new RegistryConflictException('The claimant must be an active member of the current company.');
            }
            if (! $payee) {
                throw new RegistryConflictException('The claimant Business Partner must be active in the current company.');
            }
            $expenseIds = array_values(array_unique($input['expense_ids'] ?? []));
            if (! $expenseIds) {
                throw new RegistryConflictException('A Reimbursement Claim must include at least one Expense Record.');
            }
            $expenses = Expense::where('company_id', $company->id)->whereIn('id', $expenseIds)->with('currency')->lockForUpdate()->get();
            if ($expenses->count() !== count($expenseIds) || $expenses->contains(fn ($expense) => $expense->settlement_intent !== 'reimbursement')) {
                throw new RegistryConflictException('Every selected Expense must be a same-company Reimbursement Expense.');
            }
            if ($expenses->contains(fn ($expense) => $expense->reimbursement_claim_id || in_array($expense->status, ['cancelled', 'rejected', 'closed'], true))) {
                throw new RegistryConflictException('An Expense is already claimed or is not eligible for reimbursement.');
            }
            $currencyId = (string) $expenses->first()->currency_id;
            if ($expenses->contains(fn ($expense) => (string) $expense->currency_id !== $currencyId)) {
                throw new RegistryConflictException('All Expenses in a Reimbursement Claim must use one Currency.');
            }
            $total = (string) $expenses->sum('total');
            $claim = ReimbursementClaim::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'claim_number' => $this->numbers->next($company->id, 'reimbursement'), 'claimant_user_id' => $claimant->id, 'claimant_business_partner_id' => $payee->id, 'currency_id' => $currencyId, 'business_purpose' => $input['business_purpose'], 'status' => 'draft', 'approval_status' => ! empty($input['approval_required']) ? 'pending' : 'not_required', 'payment_status' => 'unpaid', 'evidence_status' => 'complete', 'approval_required' => (bool) ($input['approval_required'] ?? false), 'evidence_required' => (bool) ($input['evidence_required'] ?? false), 'total' => $total, 'paid_amount' => '0', 'remaining_amount' => $total, 'due_date' => $input['due_date'] ?? now()->toDateString(), 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id, 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($expenses as $expense) {
                ReimbursementClaimExpense::create(['id' => (string) Str::uuid(), 'reimbursement_claim_id' => $claim->id, 'expense_id' => $expense->id, 'company_id' => $company->id, 'claimed_amount' => $expense->total, 'status' => 'included']);
                $expense->update(['reimbursement_claim_id' => $claim->id, 'updated_by' => $request->user()?->id, 'version' => $expense->version + 1]);
            }
            $this->history($claim, null, 'draft', 'EVT-EXP-010', $company, $request, 'Reimbursement Claim created.');
            $this->audit->record($request, 'expense.reimbursement.created', $claim, $company->id, [], ['claim_number' => $claim->claim_number, 'total' => $total], null, 'Reimbursement Claim created', 'A claimant-linked reimbursement claim was created.');

            return $claim->refresh()->load($this->claimRelations());
        });
    }

    public function showClaim(string $id, Company $company): ReimbursementClaim
    {
        return ReimbursementClaim::where('company_id', $company->id)->whereKey($id)->with($this->claimRelations())->firstOrFail();
    }

    public function actionClaim(ReimbursementClaim $claim, string $action, array $input, Company $company, Request $request): ReimbursementClaim
    {
        return DB::transaction(function () use ($claim, $action, $input, $company, $request) {
            $locked = ReimbursementClaim::where('company_id', $company->id)->whereKey($claim->id)->lockForUpdate()->with('expenses')->firstOrFail();
            $from = $locked->status;
            $reason = $input['reason'] ?? null;
            if ($action === 'submit') {
                if (! in_array($from, ['draft', 'returned'], true)) {
                    throw new RegistryConflictException('Only Draft or Returned Reimbursement Claims can be submitted.');
                }
                if ($locked->expenses->contains(fn ($expense) => $expense->evidence_required && ! $expense->evidences()->where('requirement_status', 'satisfied')->exists())) {
                    throw new RegistryConflictException('Required Expense evidence is missing from the Reimbursement Claim.');
                }
                $locked->update(['status' => $locked->approval_required ? 'for_approval' : 'approved', 'approval_status' => $locked->approval_required ? 'pending' : 'approved', 'submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'approved_by' => $locked->approval_required ? null : $request->user()?->id, 'approved_at' => $locked->approval_required ? null : now(), 'version' => $locked->version + 1]);
                if ($locked->approval_required) {
                    ReimbursementApproval::create(['id' => (string) Str::uuid(), 'reimbursement_claim_id' => $locked->id, 'company_id' => $company->id, 'status' => 'pending', 'action' => 'submitted', 'actor_id' => $request->user()?->id, 'submitted_version' => $locked->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
                }
            } elseif ($action === 'approve') {
                if ($from !== 'for_approval') {
                    throw new RegistryConflictException('Only Reimbursement Claims in an approval route can be approved.');
                }
                if ((int) $locked->created_by === (int) $request->user()?->id) {
                    throw new RegistryConflictException('The Claim creator cannot approve the same Reimbursement Claim.');
                }
                $locked->approvals()->where('status', 'pending')->latest()->firstOrFail()->update(['status' => 'approved', 'action' => 'approved', 'actor_id' => $request->user()?->id, 'acted_at' => now(), 'reason' => $reason]);
                $locked->update(['status' => 'approved', 'approval_status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $locked->version + 1]);
            } elseif ($action === 'reject' || $action === 'return') {
                if (! in_array($from, ['submitted', 'for_approval'], true) || ! trim((string) $reason)) {
                    throw new RegistryConflictException('A Reimbursement Claim action requires an approval-stage Claim and a reason.');
                }
                $locked->approvals()->where('status', 'pending')->latest()->first()?->update(['status' => $action === 'reject' ? 'rejected' : 'returned', 'action' => $action === 'reject' ? 'rejected' : 'returned', 'actor_id' => $request->user()?->id, 'acted_at' => now(), 'reason' => $reason]);
                $locked->update(['status' => $action === 'reject' ? 'rejected' : 'returned', 'approval_status' => $action === 'reject' ? 'rejected' : 'returned', 'return_reason' => $reason, 'version' => $locked->version + 1]);
            } elseif ($action === 'post') {
                if ($from !== 'approved') {
                    throw new RegistryConflictException('Only approved Reimbursement Claims can be posted.');
                }
                if (! $locked->claimant_business_partner_id) {
                    throw new RegistryConflictException('A claimant Business Partner is required before MDS-500 handoff.');
                }
                foreach ($locked->expenses as $expense) {
                    $this->expenses->postReimbursement($expense, $company, $request);
                }
                ReimbursementObligation::firstOrCreate(['company_id' => $company->id, 'reimbursement_claim_id' => $locked->id], ['id' => (string) Str::uuid(), 'claimant_user_id' => $locked->claimant_user_id, 'payee_id' => $locked->claimant_business_partner_id, 'currency_id' => $locked->currency_id, 'original_amount' => $locked->total, 'paid_amount' => '0', 'credited_amount' => '0', 'refunded_amount' => '0', 'adjusted_amount' => '0', 'remaining_amount' => $locked->total, 'due_date' => $locked->due_date ?? now()->toDateString(), 'due_status' => $this->dueStatus($locked->due_date), 'payment_ready' => true, 'settlement_status' => 'unpaid', 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
                $locked->update(['status' => 'payment_ready', 'remaining_amount' => $locked->total, 'posted_by' => $request->user()?->id, 'posted_at' => now(), 'version' => $locked->version + 1]);
            } elseif ($action === 'cancel') {
                if (! in_array($from, ['draft', 'returned'], true) || ! trim((string) $reason)) {
                    throw new RegistryConflictException('Only editable Reimbursement Claims can be cancelled with a reason.');
                }
                $locked->update(['status' => 'cancelled', 'cancellation_reason' => $reason, 'version' => $locked->version + 1]);
            } else {
                throw new RegistryConflictException('The Reimbursement Claim action is not supported.');
            }
            $this->history($locked, $from, $locked->status, 'EVT-EXP-'.strtoupper($action), $company, $request, $reason);

            return $locked->refresh()->load($this->claimRelations());
        });
    }

    public function payClaim(ReimbursementClaim $claim, array $input, Company $company, Request $request)
    {
        $obligation = ReimbursementObligation::where('company_id', $company->id)->where('reimbursement_claim_id', $claim->id)->firstOrFail();
        $payment = $this->payments->createReimbursementPayment($input, $obligation, $company, $request);
        $claim->update(['status' => 'payment_ready', 'payment_status' => 'pending', 'version' => $claim->version + 1]);

        return $payment;
    }

    public function createTemplate(array $input, Company $company, Request $request): RecurringExpenseTemplate
    {
        $currency = ReferenceCurrency::where('company_id', $company->id)->whereKey($input['currency_id'])->where('status', 'active')->firstOrFail();
        if (! in_array($input['frequency'], ['monthly', 'quarterly', 'annual'], true)) {
            throw new RegistryConflictException('Recurring Expenses support monthly, quarterly, and annual schedules.');
        }

        return RecurringExpenseTemplate::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'template_number' => $this->numbers->next($company->id, 'recurring_expense'), 'name' => $input['name'], 'currency_id' => $currency->id, 'payee_id' => $input['payee_id'] ?? null, 'expense_category_id' => $input['expense_category_id'], 'expense_account_title_id' => $input['expense_account_title_id'], 'settlement_intent' => $input['settlement_intent'] ?? 'pay_later', 'frequency' => $input['frequency'], 'next_run_date' => $input['next_run_date'], 'end_date' => $input['end_date'] ?? null, 'active' => true, 'amount' => $this->decimal($input['amount']), 'description' => $input['description'], 'line_defaults' => $input['line_defaults'] ?? null, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id]);
    }

    public function templates(Company $company): array
    {
        return RecurringExpenseTemplate::where('company_id', $company->id)->with(['currency', 'payee', 'category', 'account'])->orderBy('next_run_date')->get()->all();
    }

    public function generate(RecurringExpenseTemplate $template, Company $company, Request $request): RecurringExpenseOccurrence
    {
        return DB::transaction(function () use ($template, $company, $request) {
            $locked = RecurringExpenseTemplate::where('company_id', $company->id)->whereKey($template->id)->lockForUpdate()->firstOrFail();
            if (! $locked->active || ($locked->end_date && $locked->next_run_date->isAfter($locked->end_date))) {
                throw new RegistryConflictException('This Recurring Expense Template is paused or has reached its end date.');
            }
            $requestedDate = $request->input('scheduled_date');
            $date = $requestedDate ? Carbon::parse($requestedDate)->toDateString() : $locked->next_run_date->toDateString();
            $occurrence = RecurringExpenseOccurrence::where('template_id', $locked->id)->whereDate('scheduled_date', $date)->first();
            if (! $occurrence) {
                $occurrence = RecurringExpenseOccurrence::create(['id' => (string) Str::uuid(), 'template_id' => $locked->id, 'company_id' => $company->id, 'scheduled_date' => $date, 'status' => 'scheduled']);
            }
            if ($occurrence->expense_id) {
                return $occurrence->load('expense');
            }
            $defaults = $locked->line_defaults ?: [['description' => $locked->description, 'quantity' => 1, 'unit_amount' => $locked->amount, 'expense_category_id' => $locked->expense_category_id, 'expense_account_title_id' => $locked->expense_account_title_id]];
            $expense = $this->expenses->createDraft(['business_date' => $date, 'payee_id' => $locked->payee_id, 'description' => $locked->description, 'currency_id' => $locked->currency_id, 'settlement_intent' => $locked->settlement_intent, 'lines' => $defaults], $company, $request);
            $expense->update(['source_recurring_template_id' => $locked->id, 'source_recurring_occurrence_id' => $occurrence->id]);
            $occurrence->update(['expense_id' => $expense->id, 'status' => 'draft', 'generation_note' => 'Generated as a governed Expense draft; normal evidence, approval, and posting controls remain required.']);
            if ($date === $locked->next_run_date->toDateString()) {
                $locked->update(['next_run_date' => $this->nextDate($locked->next_run_date, $locked->frequency), 'updated_by' => $request->user()?->id]);
            }

            return $occurrence->refresh()->load('expense');
        });
    }

    public function toggleTemplate(RecurringExpenseTemplate $template, bool $active, Company $company, Request $request): RecurringExpenseTemplate
    {
        $template->update(['active' => $active, 'updated_by' => $request->user()?->id]);

        return $template->refresh();
    }

    public function copy(Expense $source, array $input, Company $company, Request $request): Expense
    {
        $source->load(['lines', 'currency', 'payee']);
        $lines = $source->lines->map(fn ($line) => ['expense_category_id' => $line->expense_category_id, 'expense_account_title_id' => $line->expense_account_title_id, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_amount' => $line->unit_amount, 'tax_code_id' => $line->tax_code_id])->all();
        $expense = $this->expenses->createDraft(['business_date' => $input['business_date'] ?? now()->toDateString(), 'branch_id' => $input['branch_id'] ?? $source->branch_id, 'payee_id' => $input['payee_id'] ?? $source->payee_id, 'payee_name' => $source->payee_name_snapshot, 'external_reference' => $input['external_reference'] ?? null, 'description' => $input['description'] ?? 'Copy of '.$source->expense_number, 'currency_id' => $input['currency_id'] ?? $source->currency_id, 'settlement_intent' => $input['settlement_intent'] ?? $source->settlement_intent, 'payment_term_id' => $input['payment_term_id'] ?? $source->payment_term_id, 'lines' => $lines], $company, $request);
        $expense->update(['copy_source_expense_id' => $source->id]);

        return $expense->refresh()->load(['copySource', 'lines']);
    }

    public function correction(Expense $expense, array $input, Company $company, Request $request): ExpenseAdjustmentEntry
    {
        return DB::transaction(function () use ($expense, $input, $company, $request) {
            $locked = Expense::where('company_id', $company->id)->whereKey($expense->id)->lockForUpdate()->with(['obligation', 'lines', 'currency'])->firstOrFail();
            $type = $input['adjustment_type'];
            $amount = $this->decimal($input['amount'] ?? $locked->total);
            if (! in_array($type, ['adjustment', 'credit', 'refund', 'reversal'], true) || ! $locked->accounting_transaction_id || in_array($locked->status, ['draft', 'cancelled', 'rejected'], true)) {
                throw new RegistryConflictException('Only posted Expenses support governed adjustments, credits, refunds, and reversals.');
            }
            if ($type === 'reversal' && PaymentAllocation::where('expense_obligation_id', $locked->obligation?->id)->where('status', 'applied')->exists()) {
                throw new RegistryConflictException('Reverse confirmed MDS-500 payment allocations before reversing the Expense.');
            }
            if (bccomp($amount, (string) $locked->total, 6) > 0 || bccomp($amount, '0', 6) <= 0) {
                throw new RegistryConflictException('The correction amount must be positive and no greater than the original Expense total.');
            }
            $entry = ExpenseAdjustmentEntry::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'expense_id' => $locked->id, 'expense_obligation_id' => $locked->obligation?->id, 'adjustment_type' => $type, 'amount' => $amount, 'currency_id' => $locked->currency_id, 'status' => $type === 'refund' ? 'cash_pending' : 'posted', 'reason' => $input['reason'], 'effective_date' => $input['effective_date'] ?? now()->toDateString(), 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'metadata' => ['source_expense_number' => $locked->expense_number]]);
            if ($type !== 'refund') {
                $entry->update(['accounting_transaction_id' => $this->correctionAccounting($locked, $entry, $company, $request)]);
            }
            if ($locked->obligation && $type !== 'refund') {
                $this->applyObligationEffect($locked->obligation, $type, $amount, $company, $request);
            }
            if ($type === 'reversal') {
                $locked->update(['status' => 'reversed', 'payment_status' => 'unpaid', 'remaining_amount' => '0', 'version' => $locked->version + 1]);
            } else {
                $locked->update(['status' => 'adjusted', 'version' => $locked->version + 1]);
            }
            $this->audit->record($request, 'expense.'.$type, $entry, $company->id, ['expense_id' => $locked->id, 'status' => $locked->getOriginal('status')], ['entry_id' => $entry->id, 'status' => $entry->status], $input['reason'], 'Expense correction recorded', 'A governed Expense correction preserved the original record and its audit trail.');

            return $entry->refresh();
        });
    }

    public function linkRefund(ExpenseAdjustmentEntry $entry, string $cashMovementId, Company $company, Request $request): ExpenseAdjustmentEntry
    {
        return DB::transaction(function () use ($entry, $cashMovementId, $company, $request) {
            $locked = ExpenseAdjustmentEntry::where('company_id', $company->id)->whereKey($entry->id)->lockForUpdate()->with(['expense.obligation', 'expense.lines', 'expense.currency'])->firstOrFail();
            if ($locked->adjustment_type !== 'refund' || $locked->status !== 'cash_pending') {
                throw new RegistryConflictException('Only a pending Expense Refund can be linked to an MDS-700 Cash Movement.');
            }
            if (! DB::table('cash_movements')->where('company_id', $company->id)->where('id', $cashMovementId)->exists()) {
                throw new RegistryConflictException('The Cash Movement must belong to the current company.');
            }
            $locked->update(['cash_movement_id' => $cashMovementId, 'status' => 'posted', 'accounting_transaction_id' => $this->refundAccounting($locked->expense, $locked, $cashMovementId, $company, $request)]);
            if ($locked->expense->obligation) {
                $this->applyObligationEffect($locked->expense->obligation, 'refund', (string) $locked->amount, $company, $request);
            }
            $locked->expense->update(['status' => 'adjusted', 'version' => $locked->expense->version + 1]);

            return $locked->refresh();
        });
    }

    public function corrections(Company $company, Request $request): array
    {
        $query = ExpenseAdjustmentEntry::where('company_id', $company->id)->with(['expense', 'currency'])->when($request->filled('type'), fn ($q) => $q->where('adjustment_type', $request->string('type')))->orderByDesc('effective_date')->orderByDesc('created_at');
        $paginator = $query->paginate(min((int) $request->integer('per_page', 25), 100));

        return [$paginator->items(), ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function report(string $report, Company $company, Request $request): array
    {
        $query = Expense::where('company_id', $company->id)->whereNotIn('status', ['draft', 'cancelled', 'rejected']);
        $this->dateFilter($query, $request);
        if ($report === 'by-category') {
            return $query->join('expense_lines', 'expense_lines.expense_id', '=', 'expenses.id')->join('expense_categories', 'expense_categories.id', '=', 'expense_lines.expense_category_id')->select('expense_categories.id', 'expense_categories.name', DB::raw('SUM(expense_lines.line_total) as amount'), DB::raw('COUNT(DISTINCT expenses.id) as expense_count'))->groupBy('expense_categories.id', 'expense_categories.name')->orderByDesc('amount')->get()->all();
        }
        if ($report === 'by-account') {
            return $query->join('expense_lines', 'expense_lines.expense_id', '=', 'expenses.id')->join('account_titles', 'account_titles.id', '=', 'expense_lines.expense_account_title_id')->select('account_titles.id', 'account_titles.code', 'account_titles.name', DB::raw('SUM(expense_lines.line_total) as amount'), DB::raw('COUNT(DISTINCT expenses.id) as expense_count'))->groupBy('account_titles.id', 'account_titles.code', 'account_titles.name')->orderByDesc('amount')->get()->all();
        }
        if ($report === 'by-payee') {
            return $query->select('payee_id', 'payee_name_snapshot', DB::raw('SUM(total) as amount'), DB::raw('COUNT(*) as expense_count'))->groupBy('payee_id', 'payee_name_snapshot')->orderByDesc('amount')->get()->all();
        }
        if ($report === 'unpaid') {
            return ExpenseObligation::where('company_id', $company->id)->where('remaining_amount', '>', 0)->with(['expense', 'payee', 'currency'])->orderBy('due_date')->get()->all();
        }
        if ($report === 'credit-refund-history') {
            return ExpenseAdjustmentEntry::where('company_id', $company->id)->whereIn('adjustment_type', ['credit', 'refund', 'reversal'])->with(['expense', 'currency'])->orderByDesc('effective_date')->get()->all();
        }
        if ($report === 'approval-queue') {
            return $query->whereIn('status', ['submitted', 'for_approval', 'returned'])->with(['payee', 'currency'])->orderByDesc('updated_at')->get()->all();
        }
        if ($report === 'missing-evidence') {
            return $query->where('evidence_status', 'missing')->with(['payee', 'currency'])->orderByDesc('business_date')->get()->all();
        }
        if ($report === 'duplicate-exceptions') {
            return $query->whereIn('duplicate_status', ['open', 'exact', 'probable'])->with(['payee', 'currency', 'duplicateCandidates.candidate'])->orderByDesc('updated_at')->get()->all();
        }
        if ($report === 'reimbursements') {
            return ReimbursementClaim::where('company_id', $company->id)->with(['claimant', 'claimantBusinessPartner', 'currency', 'obligation'])->orderByDesc('created_at')->get()->all();
        }
        if ($report === 'recurring-analysis') {
            return RecurringExpenseTemplate::where('company_id', $company->id)->with(['currency', 'payee'])->orderBy('next_run_date')->get()->all();
        }

        return $query->select('currency_id', DB::raw('SUM(total) as amount'), DB::raw('COUNT(*) as expense_count'), DB::raw('SUM(CASE WHEN evidence_status = \'missing\' THEN 1 ELSE 0 END) as missing_evidence_count'))->groupBy('currency_id')->with('currency')->get()->map(fn ($row) => ['currency_id' => $row->currency_id, 'currency' => $row->currency?->code, 'amount' => (string) $row->amount, 'expense_count' => (int) $row->expense_count, 'missing_evidence_count' => (int) $row->missing_evidence_count])->all();
    }

    public function previewImport(UploadedFile $file, Company $company, Request $request): ExpenseImportBatch
    {
        $rows = $this->csvRows($file);
        $batch = ExpenseImportBatch::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'batch_number' => $this->numbers->next($company->id, 'expense_import'), 'status' => 'previewed', 'row_count' => count($rows), 'valid_row_count' => 0, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $valid = 0;
        foreach ($rows as $index => $payload) {
            $errors = $this->importErrors($payload, $company);
            if (! $errors) {
                $valid++;
            }
            ExpenseImportRow::create(['id' => (string) Str::uuid(), 'batch_id' => $batch->id, 'company_id' => $company->id, 'row_number' => $index + 2, 'payload' => $payload, 'errors' => $errors ?: null, 'status' => $errors ? 'invalid' : 'valid']);
        }
        $batch->update(['valid_row_count' => $valid]);

        return $batch->refresh()->load('rows');
    }

    public function applyImport(ExpenseImportBatch $batch, Company $company, Request $request): ExpenseImportBatch
    {
        return DB::transaction(function () use ($batch, $company, $request) {
            $locked = ExpenseImportBatch::where('company_id', $company->id)->whereKey($batch->id)->lockForUpdate()->with('rows')->firstOrFail();
            if ($locked->status === 'applied') {
                return $locked;
            }
            foreach ($locked->rows->where('status', 'valid') as $row) {
                $expense = $this->expenses->createDraft($this->importInput($row->payload), $company, $request);
                $row->update(['status' => 'created', 'expense_id' => $expense->id]);
            }
            $locked->update(['status' => 'applied', 'created_count' => $locked->rows->where('status', 'created')->count()]);

            return $locked->refresh()->load('rows.expense');
        });
    }

    private function correctionAccounting(Expense $expense, ExpenseAdjustmentEntry $entry, Company $company, Request $request): string
    {
        $liability = DB::table('account_titles')->where('company_id', $company->id)->where('classification', 'liability')->where('status', 'active')->where('posting_eligible', true)->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%payable%'])->orWhereRaw('LOWER(name) LIKE ?', ['%accrued%']))->first();
        $line = $expense->lines->first();
        if (! $liability || ! $line) {
            throw new RegistryConflictException('A posting liability Account Title and original Expense line are required for a correction.');
        }
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'idempotency_key' => 'expense-correction:'.$entry->id, 'transaction_type' => 'expense_correction', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $entry->effective_date, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $transaction = AccountingTransaction::create(['id' => (string) Str::uuid(), 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => 'expense_correction', 'status' => 'posted', 'business_date' => $entry->effective_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $transaction->lines()->create(['id' => (string) Str::uuid(), 'account_title_id' => $liability->id, 'debit' => $entry->amount, 'credit' => '0', 'currency_code' => $expense->currency->code, 'description' => ucfirst($entry->adjustment_type).' for '.$expense->expense_number]);
        $transaction->lines()->create(['id' => (string) Str::uuid(), 'account_title_id' => $line->expense_account_title_id, 'debit' => '0', 'credit' => $entry->amount, 'currency_code' => $expense->currency->code, 'description' => ucfirst($entry->adjustment_type).' for '.$expense->expense_number]);

        return $transaction->id;
    }

    private function refundAccounting(Expense $expense, ExpenseAdjustmentEntry $entry, string $cashMovementId, Company $company, Request $request): string
    {
        $movement = DB::table('cash_movements')->where('cash_movements.company_id', $company->id)->where('cash_movements.id', $cashMovementId)->join('cash_accounts', 'cash_accounts.id', '=', 'cash_movements.cash_account_id')->select('cash_accounts.account_title_id')->first();
        $line = $expense->lines->first();
        if (! $movement || ! $line) {
            throw new RegistryConflictException('The linked MDS-700 Cash Movement must resolve a cash Account Title for the refund.');
        }
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'idempotency_key' => 'expense-refund:'.$entry->id, 'transaction_type' => 'expense_refund', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $entry->effective_date, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $transaction = AccountingTransaction::create(['id' => (string) Str::uuid(), 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => 'expense_refund', 'status' => 'posted', 'business_date' => $entry->effective_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $transaction->lines()->create(['id' => (string) Str::uuid(), 'account_title_id' => $movement->account_title_id, 'debit' => $entry->amount, 'credit' => '0', 'currency_code' => $expense->currency->code, 'description' => 'Refund for '.$expense->expense_number]);
        $transaction->lines()->create(['id' => (string) Str::uuid(), 'account_title_id' => $line->expense_account_title_id, 'debit' => '0', 'credit' => $entry->amount, 'currency_code' => $expense->currency->code, 'description' => 'Refund recovery for '.$expense->expense_number]);

        return $transaction->id;
    }

    private function applyObligationEffect(ExpenseObligation $obligation, string $type, string $amount, Company $company, Request $request): void
    {
        $field = match ($type) {
            'credit' => 'credited_amount', 'adjustment' => 'adjusted_amount', 'reversal' => 'adjusted_amount', default => 'refunded_amount'
        };
        $obligation->update([$field => bcadd((string) $obligation->{$field}, $amount, 6)]);
        $reduction = bcadd(bcadd((string) $obligation->credited_amount, (string) $obligation->adjusted_amount, 6), (string) $obligation->refunded_amount, 6);
        $remaining = bcsub(bcsub((string) $obligation->original_amount, (string) $obligation->paid_amount, 6), $reduction, 6);
        $remaining = bccomp($remaining, '0', 6) < 0 ? '0' : $remaining;
        $settlement = bccomp($remaining, '0', 6) === 0 ? 'settled' : (bccomp((string) $obligation->paid_amount, '0', 6) > 0 ? 'partially_paid' : 'unpaid');
        $obligation->update(['remaining_amount' => $remaining, 'payment_ready' => bccomp($remaining, '0', 6) > 0, 'settlement_status' => $settlement, 'version' => $obligation->version + 1]);
        Expense::where('company_id', $company->id)->whereKey($obligation->expense_id)->update(['remaining_amount' => $remaining, 'payment_status' => bccomp($remaining, '0', 6) === 0 ? 'paid' : (bccomp((string) $obligation->paid_amount, '0', 6) > 0 ? 'partially_paid' : 'unpaid')]);
    }

    private function csvRows(UploadedFile $file): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim((string) $file->get()), -1, PREG_SPLIT_NO_EMPTY);
        if (count($lines) < 2) {
            throw new RegistryConflictException('The Expense import must contain a header row and at least one data row.');
        }
        $headers = array_map(fn ($header) => trim((string) $header), str_getcsv(array_shift($lines)));

        return array_map(function ($line) use ($headers) {
            $values = str_getcsv($line);

            return collect($headers)->mapWithKeys(fn ($header, $index) => [$header => trim((string) ($values[$index] ?? ''))])->all();
        }, $lines);
    }

    private function importErrors(array $payload, Company $company): array
    {
        $errors = [];
        foreach (['business_date', 'description', 'currency_id', 'expense_category_id', 'amount'] as $required) {
            if (($payload[$required] ?? '') === '') {
                $errors[$required] = 'This field is required.';
            }
        }
        if (! empty($payload['currency_id']) && ! ReferenceCurrency::where('company_id', $company->id)->whereKey($payload['currency_id'])->where('status', 'active')->exists()) {
            $errors['currency_id'] = 'Currency is not active in this company.';
        }
        if (! empty($payload['expense_category_id']) && ! DB::table('expense_categories')->where('company_id', $company->id)->where('id', $payload['expense_category_id'])->where('status', 'active')->exists()) {
            $errors['expense_category_id'] = 'Expense Category is not active in this company.';
        }
        if (! is_numeric($payload['amount'] ?? null) || bccomp((string) ($payload['amount'] ?? '0'), '0', 6) <= 0) {
            $errors['amount'] = 'Amount must be greater than zero.';
        }
        $settlementIntent = in_array($payload['settlement_intent'] ?? '', ['paid_now', 'pay_later', 'reimbursement'], true) ? $payload['settlement_intent'] : 'pay_later';
        if ($settlementIntent !== 'reimbursement' && empty($payload['payee_id'])) {
            $errors['payee_id'] = 'A Payee is required for a paid or payable Expense.';
        }
        if (! empty($payload['payee_id']) && ! DB::table('business_partners')->where('company_id', $company->id)->where('id', $payload['payee_id'])->where('status', 'active')->exists()) {
            $errors['payee_id'] = 'Payee is not active in this company.';
        }

        return $errors;
    }

    private function importInput(array $payload): array
    {
        return ['business_date' => $payload['business_date'], 'payee_id' => ($payload['payee_id'] ?? '') ?: null, 'external_reference' => ($payload['external_reference'] ?? '') ?: null, 'description' => $payload['description'], 'currency_id' => $payload['currency_id'], 'settlement_intent' => in_array($payload['settlement_intent'] ?? '', ['paid_now', 'pay_later', 'reimbursement'], true) ? $payload['settlement_intent'] : 'pay_later', 'lines' => [['expense_category_id' => $payload['expense_category_id'], 'expense_account_title_id' => ($payload['expense_account_title_id'] ?? '') ?: null, 'description' => $payload['description'], 'quantity' => 1, 'unit_amount' => $payload['amount']]]];
    }

    private function claimRelations(): array
    {
        return ['claimant', 'claimantBusinessPartner', 'currency', 'expenses.currency', 'expenses.payee', 'obligation.payee', 'obligation.currency', 'approvals', 'statusHistory'];
    }

    private function history(ReimbursementClaim $claim, ?string $from, string $to, string $event, Company $company, Request $request, ?string $reason): void
    {
        ReimbursementStatusHistory::create(['id' => (string) Str::uuid(), 'reimbursement_claim_id' => $claim->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => $to, 'event_code' => $event, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $claim->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function dateFilter($query, Request $request): void
    {
        $query->when($request->filled('from'), fn ($q) => $q->whereDate('business_date', '>=', $request->date('from')))->when($request->filled('to'), fn ($q) => $q->whereDate('business_date', '<=', $request->date('to')));
    }

    private function dueStatus(?Carbon $date): string
    {
        return ! $date ? 'not_due' : ($date->isPast() && ! $date->isToday() ? 'overdue' : ($date->isToday() ? 'due_today' : 'not_due'));
    }

    private function nextDate(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'quarterly' => $date->copy()->addMonthsNoOverflow(3), 'annual' => $date->copy()->addYearNoOverflow(), default => $date->copy()->addMonthNoOverflow()
        };
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value) || bccomp((string) $value, '0', 6) <= 0) {
            throw new RegistryConflictException('The amount must be greater than zero.');
        }

        return bcadd((string) $value, '0', 6);
    }
}
