<?php

namespace App\Services;

use App\Events\PaymentLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\ExpenseObligation;
use App\Models\PayableEffect;
use App\Models\PayableOpenItem;
use App\Models\PaymentAdvance;
use App\Models\PaymentAllocation;
use App\Models\PaymentApproval;
use App\Models\PaymentConfirmation;
use App\Models\PaymentCorrection;
use App\Models\PaymentExecutionAttempt;
use App\Models\PaymentInstruction;
use App\Models\PaymentInstructionSource;
use App\Models\PaymentMethod;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestSource;
use App\Models\PaymentRequestStatusHistory;
use App\Models\PaymentStatusHistory;
use App\Models\ReferenceCurrency;
use App\Models\ReimbursementObligation;
use App\Models\RemittanceAdvice;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class PaymentService
{
    public function __construct(private readonly CashDocumentNumberService $numbers, private readonly CashMovementService $cashMovements, private readonly AuditService $audit, private readonly ExpenseSettlementService $expenseSettlement, private readonly ReimbursementSettlementService $reimbursementSettlement) {}

    public function lookups(Company $company): array
    {
        return ['payment_methods' => PaymentMethod::where('company_id', $company->id)->where('status', 'active')->where('supports_outgoing', true)->orderBy('name')->get(['id', 'code', 'name', 'method_class', 'clearing_behavior', 'requires_external_reference', 'requires_account_selection']), 'cash_accounts' => CashAccount::where('company_id', $company->id)->where('status', 'active')->whereHas('capabilities', fn ($q) => $q->where('capability', 'MAKE_PAYMENTS')->where('enabled', true))->with('currency')->orderBy('name')->get(['id', 'code', 'name', 'currency_id', 'branch_id']), 'currencies' => ReferenceCurrency::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'symbol', 'decimal_precision']), 'branches' => DB::table('branches')->where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name'])];
    }

    public function workbench(Company $company, Request $request): array
    {
        $query = PayableOpenItem::where('company_id', $company->id)->with(['supplier', 'currency', 'sourceInvoice', 'branch'])->where('settlement_status', '!=', 'settled')->where('remaining_amount', '>', 0)->where('hold_status', 'not_held')->whereHas('supplier', fn ($q) => $q->where('status', 'active')->whereHas('roles', fn ($role) => $role->where('role', 'supplier')->where('status', 'active')))->whereHas('sourceInvoice', fn ($q) => $q->where('status', 'posted'));
        $query->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->string('supplier_id')));
        $query->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id')));
        $query->when($request->filled('currency_id'), fn ($q) => $q->where('currency_id', $request->string('currency_id')));
        $query->when($request->filled('due_status'), fn ($q) => $q->where('due_status', $request->string('due_status')));
        $query->when($request->boolean('overdue'), fn ($q) => $q->where('due_status', 'overdue'));
        $query->when($request->filled('source_document_number'), fn ($q) => $q->where('source_document_number', 'like', '%'.$request->string('source_document_number').'%'));
        $query->when($request->filled('q'), fn ($q) => $q->where(fn ($inner) => $inner->where('source_document_number', 'like', '%'.$request->string('q').'%')->orWhereHas('supplier', fn ($supplier) => $supplier->where('display_name', 'like', '%'.$request->string('q').'%')->orWhere('code', 'like', '%'.$request->string('q').'%'))));
        $paginator = $query->orderByRaw("CASE WHEN due_status = 'overdue' THEN 0 WHEN due_status = 'due_today' THEN 1 ELSE 2 END")->orderBy('due_date')->paginate(min((int) $request->integer('per_page', 20), 100));

        return [$paginator->items(), ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function summary(Company $company): array
    {
        $base = PayableOpenItem::where('company_id', $company->id)->where('settlement_status', '!=', 'settled');

        return ['eligible_count' => (clone $base)->where('remaining_amount', '>', 0)->where('hold_status', 'not_held')->count(), 'eligible_amount' => (string) (clone $base)->where('remaining_amount', '>', 0)->where('hold_status', 'not_held')->sum('remaining_amount'), 'due_today' => (clone $base)->where('due_status', 'due_today')->where('remaining_amount', '>', 0)->sum('remaining_amount'), 'overdue' => (clone $base)->where('due_status', 'overdue')->where('remaining_amount', '>', 0)->sum('remaining_amount'), 'on_hold' => (clone $base)->where('hold_status', '!=', 'not_held')->where('remaining_amount', '>', 0)->count(), 'pending_approval' => PaymentInstruction::where('company_id', $company->id)->where('status', 'pending_approval')->count(), 'scheduled' => PaymentInstruction::where('company_id', $company->id)->where('status', 'scheduled')->count(), 'pending_confirmation' => PaymentInstruction::where('company_id', $company->id)->where('status', 'pending_confirmation')->count(), 'unallocated' => PaymentInstruction::where('company_id', $company->id)->whereIn('status', ['confirmed', 'partially_allocated'])->sum('unapplied_amount')];
    }

    public function requests(Company $company, Request $request): array
    {
        $paginator = PaymentRequest::where('company_id', $company->id)->with(['supplier', 'currency', 'sources'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 20), 100));

        return [$paginator->items(), ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function payments(Company $company, Request $request): array
    {
        $paginator = PaymentInstruction::where('company_id', $company->id)->with($this->paymentRelations())->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->string('supplier_id')))->when($request->filled('payment_method_id'), fn ($q) => $q->where('payment_method_id', $request->string('payment_method_id')))->when($request->filled('cash_account_id'), fn ($q) => $q->where('cash_account_id', $request->string('cash_account_id')))->when($request->filled('q'), fn ($q) => $q->where(fn ($inner) => $inner->where('payment_number', 'like', '%'.$request->string('q').'%')->orWhere('external_reference', 'like', '%'.$request->string('q').'%')))->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 20), 100));

        return [$paginator->items(), ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function createRequest(array $input, Company $company, Request $request): PaymentRequest
    {
        return DB::transaction(function () use ($input, $company, $request) {
            [$supplier, $currency, $branch, $sources, $total] = $this->validateSources($input['supplier_id'], $input['currency_id'], $input['branch_id'] ?? null, $input['sources'], $company);
            $requestedAmount = isset($input['requested_amount']) ? $this->decimal($input['requested_amount']) : $total;
            if (bccomp($requestedAmount, $total, 6) !== 0) {
                throw new RegistryConflictException('The payment request amount must equal its source proposal total.');
            }
            $requestDocument = PaymentRequest::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'request_number' => $this->numbers->next($company->id, 'payment_request'), 'supplier_id' => $supplier->id, 'branch_id' => $branch?->id, 'currency_id' => $currency->id, 'requested_amount' => $requestedAmount, 'requested_payment_date' => $input['requested_payment_date'], 'reason' => $input['reason'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'status' => 'draft', 'requested_by' => $request->user()?->id, 'requested_at' => now(), 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($sources as $source) {
                PaymentRequestSource::create(['id' => (string) Str::uuid(), 'payment_request_id' => $requestDocument->id, 'company_id' => $company->id, 'payable_open_item_id' => $source['payable']->id, 'source_document_number' => $source['payable']->source_document_number, 'proposed_amount' => $source['amount'], 'currency_id' => $currency->id]);
            }
            $this->historyRequest($requestDocument, null, 'draft', 'EVT-PAY-001', $request, 'Payment Request created.');
            $this->event('EVT-PAY-001', PaymentRequest::class, $requestDocument->id, $company, $request);

            return $requestDocument->load(['supplier', 'currency', 'sources']);
        });
    }

    public function transitionRequest(PaymentRequest $document, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): PaymentRequest
    {
        $result = DB::transaction(function () use ($document, $action, $company, $request, $reason, $version) {
            $locked = PaymentRequest::where('company_id', $company->id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked->version, $version);
            $from = $locked->status;
            $updates = ['version' => $locked->version + 1];
            $event = 'EVT-PAY-005';
            if ($action === 'submit' && $from === 'draft') {
                $updates += ['status' => 'submitted', 'submitted_by' => $request->user()?->id, 'submitted_at' => now()];
                $event = 'EVT-PAY-003';
            } elseif ($action === 'approve' && $from === 'submitted') {
                $updates += ['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now()];
                $event = 'EVT-PAY-004';
            } elseif ($action === 'return' && $from === 'submitted') {
                $updates += ['status' => 'returned', 'reviewed_by' => $request->user()?->id, 'rejection_reason' => $reason];
                $event = 'EVT-PAY-005';
            } elseif ($action === 'reject' && in_array($from, ['submitted', 'approved'], true)) {
                $updates += ['status' => 'rejected', 'rejection_reason' => $reason];
            } elseif ($action === 'cancel' && in_array($from, ['draft', 'submitted', 'approved'], true)) {
                $updates += ['status' => 'cancelled', 'cancellation_reason' => $reason];
            } else {
                throw new RegistryConflictException('The Payment Request cannot perform this action from its current status.', ['status' => $from, 'action' => $action]);
            }
            $locked->update($updates);
            $this->historyRequest($locked, $from, $locked->status, $event, $request, $reason);
            $this->event($event, PaymentRequest::class, $locked->id, $company, $request);

            return $locked;
        });

        return $result->load(['supplier', 'currency', 'sources']);
    }

    public function createPayment(array $input, Company $company, Request $request, ?PaymentRequest $sourceRequest = null): PaymentInstruction
    {
        return DB::transaction(function () use ($input, $company, $request, $sourceRequest) {
            $requestSources = $sourceRequest ? $sourceRequest->sources()->get()->map(fn ($source) => ['payable_open_item_id' => $source->payable_open_item_id, 'amount' => (string) $source->proposed_amount])->all() : $input['sources'];
            [$supplier, $currency, $branch, $sources, $gross] = $this->validateSources($input['supplier_id'], $input['currency_id'], $input['branch_id'] ?? null, $requestSources, $company);
            $method = $this->paymentMethod($input['payment_method_id'], $company, $input['payment_date']);
            $account = $this->cashAccount($input['cash_account_id'], $company, $currency->id, $branch?->id, $method);
            if ($sourceRequest && $sourceRequest->status !== 'approved') {
                throw new RegistryConflictException('Only an approved Payment Request can be converted into a Payment Instruction.');
            }
            $this->duplicateRisk($company, $supplier->id, $currency->id, $gross, $input['payment_date'], $input['reference'] ?? null, $request, (bool) ($input['duplicate_override'] ?? false), $input['duplicate_override_reason'] ?? null);
            $payment = PaymentInstruction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payment_number' => $this->numbers->next($company->id, 'payment'), 'payment_request_id' => $sourceRequest?->id, 'supplier_id' => $supplier->id, 'branch_id' => $branch?->id, 'currency_id' => $currency->id, 'payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'payment_date' => $input['payment_date'], 'scheduled_date' => $input['scheduled_date'] ?? null, 'gross_amount' => $gross, 'discount_amount' => '0', 'withholding_amount' => '0', 'fee_amount' => '0', 'tax_amount' => '0', 'net_amount' => $gross, 'confirmed_amount' => '0', 'allocated_amount' => '0', 'unapplied_amount' => '0', 'reference' => $input['reference'] ?? null, 'remittance_details' => $input['remittance_details'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'status' => 'draft', 'instrument_type' => $this->instrumentType($method), 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($sources as $source) {
                PaymentInstructionSource::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $payment->id, 'company_id' => $company->id, 'payable_open_item_id' => $source['payable']->id, 'source_document_number' => $source['payable']->source_document_number, 'currency_id' => $currency->id, 'requested_amount' => $source['amount'], 'allocated_amount' => '0']);
            }
            if ($sourceRequest) {
                $sourceRequest->update(['status' => 'converted', 'version' => $sourceRequest->version + 1]);
                $this->historyRequest($sourceRequest, 'approved', 'converted', 'EVT-PAY-002', $request, 'Payment Request converted into a Payment Instruction.');
                $this->event('EVT-PAY-002', PaymentRequest::class, $sourceRequest->id, $company, $request);
            }
            $this->history($payment, null, 'draft', 'EVT-PAY-002', $request, 'Payment Instruction created.');
            $this->event('EVT-PAY-002', PaymentInstruction::class, $payment->id, $company, $request);

            return $payment->load($this->paymentRelations());
        });
    }

    public function createExpensePayment(array $input, ExpenseObligation $obligation, Company $company, Request $request): PaymentInstruction
    {
        return DB::transaction(function () use ($input, $obligation, $company, $request) {
            $lockedObligation = ExpenseObligation::where('company_id', $company->id)->whereKey($obligation->id)->lockForUpdate()->with(['expense', 'payee', 'currency'])->firstOrFail();
            if (! $lockedObligation->payment_ready || bccomp((string) $lockedObligation->remaining_amount, '0', 6) <= 0) {
                throw new RegistryConflictException('The Expense Obligation is not currently payment-ready.');
            }
            $payee = BusinessPartner::where('company_id', $company->id)->whereKey($lockedObligation->payee_id)->where('status', 'active')->first();
            if (! $payee) {
                throw new RegistryConflictException('The Expense Payee is inactive or outside the current company.');
            }
            $amount = isset($input['amount']) ? $this->decimal($input['amount']) : (string) $lockedObligation->remaining_amount;
            if (bccomp($amount, (string) $lockedObligation->remaining_amount, 6) > 0) {
                throw new RegistryConflictException('The requested payment exceeds the Expense Obligation remaining amount.');
            }
            $date = $input['payment_date'];
            $method = $this->paymentMethod($input['payment_method_id'], $company, $date);
            $account = $this->cashAccount($input['cash_account_id'], $company, $lockedObligation->currency_id, $lockedObligation->expense?->branch_id, $method);
            $this->duplicateRisk($company, $payee->id, $lockedObligation->currency_id, $amount, $date, $input['reference'] ?? $lockedObligation->expense?->expense_number, $request, false, null);
            $payment = PaymentInstruction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payment_number' => $this->numbers->next($company->id, 'payment'), 'payment_request_id' => null, 'supplier_id' => $payee->id, 'branch_id' => $lockedObligation->expense?->branch_id, 'currency_id' => $lockedObligation->currency_id, 'payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'payment_date' => $date, 'scheduled_date' => $input['scheduled_date'] ?? null, 'gross_amount' => $amount, 'discount_amount' => '0', 'withholding_amount' => '0', 'fee_amount' => '0', 'tax_amount' => '0', 'net_amount' => $amount, 'confirmed_amount' => '0', 'allocated_amount' => '0', 'unapplied_amount' => '0', 'reference' => $input['reference'] ?? $lockedObligation->expense?->expense_number, 'remittance_details' => $input['remittance_details'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'source_kind' => 'expense_obligation', 'status' => 'draft', 'instrument_type' => $this->instrumentType($method), 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            PaymentInstructionSource::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $payment->id, 'company_id' => $company->id, 'payable_open_item_id' => null, 'expense_obligation_id' => $lockedObligation->id, 'source_document_number' => $lockedObligation->expense?->expense_number ?? 'Expense', 'currency_id' => $lockedObligation->currency_id, 'requested_amount' => $amount, 'allocated_amount' => '0']);
            $this->history($payment, null, 'draft', 'EVT-EXP-009', $request, 'Expense Obligation handed to MDS-500.');
            $this->event('EVT-EXP-009', PaymentInstruction::class, $payment->id, $company, $request);

            return $payment->load($this->paymentRelations());
        });
    }

    public function createReimbursementPayment(array $input, ReimbursementObligation $obligation, Company $company, Request $request): PaymentInstruction
    {
        return DB::transaction(function () use ($input, $obligation, $company, $request) {
            $locked = ReimbursementObligation::where('company_id', $company->id)->whereKey($obligation->id)->lockForUpdate()->with(['claim', 'payee', 'currency'])->firstOrFail();
            if (! $locked->payment_ready || bccomp((string) $locked->remaining_amount, '0', 6) <= 0) {
                throw new RegistryConflictException('The Reimbursement Obligation is not currently payment-ready.');
            }
            $payee = BusinessPartner::where('company_id', $company->id)->whereKey($locked->payee_id)->where('status', 'active')->first();
            if (! $payee) {
                throw new RegistryConflictException('The claimant Payee is inactive or outside the current company.');
            }
            $amount = isset($input['amount']) ? $this->decimal($input['amount']) : (string) $locked->remaining_amount;
            if (bccomp($amount, (string) $locked->remaining_amount, 6) > 0) {
                throw new RegistryConflictException('The requested reimbursement exceeds the remaining obligation.');
            }
            $date = $input['payment_date'];
            $method = $this->paymentMethod($input['payment_method_id'], $company, $date);
            $account = $this->cashAccount($input['cash_account_id'], $company, $locked->currency_id, null, $method);
            $this->duplicateRisk($company, $payee->id, $locked->currency_id, $amount, $date, $input['reference'] ?? $locked->claim?->claim_number, $request, false, null);
            $payment = PaymentInstruction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payment_number' => $this->numbers->next($company->id, 'payment'), 'payment_request_id' => null, 'supplier_id' => $payee->id, 'branch_id' => null, 'currency_id' => $locked->currency_id, 'payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'payment_date' => $date, 'scheduled_date' => $input['scheduled_date'] ?? null, 'gross_amount' => $amount, 'discount_amount' => '0', 'withholding_amount' => '0', 'fee_amount' => '0', 'tax_amount' => '0', 'net_amount' => $amount, 'confirmed_amount' => '0', 'allocated_amount' => '0', 'unapplied_amount' => '0', 'reference' => $input['reference'] ?? $locked->claim?->claim_number, 'remittance_details' => $input['remittance_details'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'source_kind' => 'reimbursement_obligation', 'status' => 'draft', 'instrument_type' => $this->instrumentType($method), 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            PaymentInstructionSource::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $payment->id, 'company_id' => $company->id, 'payable_open_item_id' => null, 'expense_obligation_id' => null, 'reimbursement_obligation_id' => $locked->id, 'source_document_number' => $locked->claim?->claim_number ?? 'Reimbursement', 'currency_id' => $locked->currency_id, 'requested_amount' => $amount, 'allocated_amount' => '0']);
            $this->history($payment, null, 'draft', 'EVT-EXP-009', $request, 'Reimbursement Obligation handed to MDS-500.');
            $this->event('EVT-EXP-009', PaymentInstruction::class, $payment->id, $company, $request);

            return $payment->load($this->paymentRelations());
        });
    }

    public function createAdvance(array $input, Company $company, Request $request): PaymentInstruction
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $supplier = BusinessPartner::where('company_id', $company->id)->whereKey($input['supplier_id'])->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'supplier')->where('status', 'active'))->first();
            $currency = ReferenceCurrency::where('company_id', $company->id)->whereKey($input['currency_id'])->where('status', 'active')->first();
            if (! $supplier || ! $currency) {
                throw new RegistryConflictException('An active same-company Supplier and Currency are required for a Supplier Advance.');
            }
            $method = $this->paymentMethod($input['payment_method_id'], $company, $input['payment_date']);
            $account = $this->cashAccount($input['cash_account_id'], $company, $currency->id, $input['branch_id'] ?? null, $method);
            $amount = $this->decimal($input['amount']);
            $this->duplicateRisk($company, $supplier->id, $currency->id, $amount, $input['payment_date'], $input['reference'] ?? null, $request, (bool) ($input['duplicate_override'] ?? false), $input['duplicate_override_reason'] ?? null);
            $payment = PaymentInstruction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payment_number' => $this->numbers->next($company->id, 'payment'), 'supplier_id' => $supplier->id, 'branch_id' => $input['branch_id'] ?? null, 'currency_id' => $currency->id, 'payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'payment_date' => $input['payment_date'], 'scheduled_date' => $input['scheduled_date'] ?? null, 'gross_amount' => $amount, 'discount_amount' => '0', 'withholding_amount' => '0', 'fee_amount' => '0', 'tax_amount' => '0', 'net_amount' => $amount, 'confirmed_amount' => '0', 'allocated_amount' => '0', 'unapplied_amount' => '0', 'reference' => $input['reference'] ?? null, 'remittance_details' => $input['remittance_details'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'source_kind' => 'supplier_advance', 'status' => 'draft', 'instrument_type' => $this->instrumentType($method), 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            PaymentAdvance::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payment_instruction_id' => $payment->id, 'supplier_id' => $supplier->id, 'currency_id' => $currency->id, 'original_amount' => $amount, 'applied_amount' => '0', 'available_amount' => '0', 'status' => 'pending', 'reason' => $input['reason'] ?? null, 'created_by' => $request->user()?->id]);
            $this->history($payment, null, 'draft', 'EVT-PAY-002', $request, 'Supplier Advance Payment Instruction created.');
            $this->event('EVT-PAY-002', PaymentInstruction::class, $payment->id, $company, $request);

            return $payment->load($this->paymentRelations());
        });
    }

    public function transitionPayment(PaymentInstruction $payment, string $action, Company $company, Request $request, array $input = []): PaymentInstruction
    {
        $result = DB::transaction(function () use ($payment, $action, $company, $request, $input) {
            $locked = PaymentInstruction::where('company_id', $company->id)->whereKey($payment->id)->lockForUpdate()->with(['paymentMethod', 'cashAccount.currency', 'sources.payable'])->firstOrFail();
            $this->assertVersion($locked->version, $input['version'] ?? null);
            $from = $locked->status;
            if ($action === 'submit') {
                if ($from !== 'draft') {
                    throw new RegistryConflictException('Only Draft Payment Instructions can be submitted.');
                }
                $this->revalidatePaymentSources($locked, $company);
                $locked->update(['status' => 'pending_approval', 'submitted_version' => $locked->version, 'submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'version' => $locked->version + 1]);
                PaymentApproval::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $locked->id, 'company_id' => $company->id, 'status' => 'pending', 'action' => 'submitted', 'submitted_version' => $locked->submitted_version, 'authority_context' => ['amount' => (string) $locked->net_amount, 'method_id' => $locked->payment_method_id, 'cash_account_id' => $locked->cash_account_id, 'supplier_id' => $locked->supplier_id, 'branch_id' => $locked->branch_id], 'correlation_id' => $request->attributes->get('correlation_id')]);
                $this->finishTransition($locked, $from, 'pending_approval', 'EVT-PAY-003', $request, 'Payment submitted for approval.');
            } elseif ($action === 'approve') {
                if ($from !== 'pending_approval') {
                    throw new RegistryConflictException('Only Payment Instructions pending approval can be approved.');
                }
                $this->revalidatePaymentSources($locked, $company);
                $approval = $locked->approvals()->where('status', 'pending')->latest()->lockForUpdate()->firstOrFail();
                $approval->update(['status' => 'approved', 'action' => 'approved', 'actor_id' => $request->user()?->id, 'acted_at' => now(), 'reason' => $input['reason'] ?? null]);
                $locked->update(['status' => $locked->scheduled_date && $locked->scheduled_date->isFuture() ? 'scheduled' : 'ready', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $locked->version + 1]);
                $this->finishTransition($locked, $from, $locked->status, 'EVT-PAY-004', $request, 'Payment approved.');
            } elseif ($action === 'return') {
                if ($from !== 'pending_approval') {
                    throw new RegistryConflictException('Only Payment Instructions pending approval can be returned.');
                }
                $approval = $locked->approvals()->where('status', 'pending')->latest()->first();
                $approval?->update(['status' => 'returned', 'action' => 'returned', 'actor_id' => $request->user()?->id, 'acted_at' => now(), 'reason' => $input['reason'] ?? null]);
                $locked->update(['status' => 'draft', 'version' => $locked->version + 1]);
                $this->finishTransition($locked, $from, 'draft', 'EVT-PAY-005', $request, $input['reason'] ?? 'Payment returned for correction.');
            } elseif ($action === 'reject') {
                if (! in_array($from, ['pending_approval', 'approved', 'ready', 'scheduled'], true)) {
                    throw new RegistryConflictException('This Payment Instruction cannot be rejected from its current status.');
                }
                $locked->update(['status' => 'rejected', 'failure_reason' => $input['reason'] ?? 'Payment rejected.', 'version' => $locked->version + 1]);
                $this->finishTransition($locked, $from, 'rejected', 'EVT-PAY-005', $request, $input['reason'] ?? null);
            } elseif ($action === 'schedule') {
                if (! in_array($from, ['approved', 'ready'], true)) {
                    throw new RegistryConflictException('Only approved Payment Instructions can be scheduled.');
                }
                $date = $input['scheduled_date'] ?? $locked->scheduled_date?->toDateString();
                if (! $date || $date <= now()->toDateString()) {
                    throw new RegistryConflictException('A future scheduled payment date is required.');
                }
                $locked->update(['status' => 'scheduled', 'scheduled_date' => $date, 'scheduled_by' => $request->user()?->id, 'scheduled_at' => now(), 'version' => $locked->version + 1]);
                $this->finishTransition($locked, $from, 'scheduled', 'EVT-PAY-006', $request, 'Payment scheduled.');
            } elseif ($action === 'release') {
                $this->release($locked, $from, $company, $request);
            } elseif ($action === 'confirm') {
                $this->confirm($locked, $from, $company, $request, $input);
            } elseif ($action === 'pending') {
                if (! in_array($from, ['released', 'pending_confirmation'], true)) {
                    throw new RegistryConflictException('Only released payments can be placed into pending confirmation.');
                }
                $attempt = $locked->attempts()->latest('attempt_number')->lockForUpdate()->first();
                if (! $attempt) {
                    $attempt = $this->attempt($locked, $request, 'pending');
                }
                $attempt->update(['status' => 'pending', 'response_code' => $input['response_code'] ?? null, 'response_message' => $input['response_message'] ?? null]);
                $locked->update(['status' => 'pending_confirmation', 'execution_state' => 'pending', 'confirmation_state' => 'pending', 'version' => $locked->version + 1]);
                $this->finishTransition($locked, $from, 'pending_confirmation', 'EVT-PAY-012', $request, $input['reason'] ?? 'Payment result is pending.');
            } elseif ($action === 'fail' || $action === 'reject_channel') {
                if (! in_array($from, ['released', 'pending_confirmation'], true)) {
                    throw new RegistryConflictException('Only released or pending payments can record a channel failure.');
                }
                $attempt = $locked->attempts()->latest('attempt_number')->lockForUpdate()->first();
                if (! $attempt) {
                    $attempt = $this->attempt($locked, $request, 'electronic');
                }
                $attempt->update(['status' => $action === 'fail' ? 'failed' : 'failed', 'response_code' => $input['response_code'] ?? null, 'response_message' => $input['reason'] ?? $input['response_message'] ?? null, 'finished_at' => now()]);
                $status = $action === 'fail' ? 'failed' : 'rejected';
                $locked->update(['status' => $status, 'execution_state' => 'failed', 'confirmation_state' => $status, 'failure_reason' => $input['reason'] ?? $input['response_message'] ?? 'Payment channel failed.', 'version' => $locked->version + 1]);
                $this->finishTransition($locked, $from, $status, 'EVT-PAY-013', $request, $locked->failure_reason);
            } elseif ($action === 'cancel') {
                if (in_array($from, ['released', 'pending_confirmation', 'confirmed', 'allocated', 'partially_allocated'], true)) {
                    throw new RegistryConflictException('A released or confirmed payment requires its governed correction workflow.');
                }
                if (! trim((string) ($input['reason'] ?? ''))) {
                    throw new RegistryConflictException('A Payment cancellation reason is required.');
                }
                $locked->update(['status' => 'cancelled', 'cancellation_reason' => $input['reason'] ?? null, 'cancelled_by' => $request->user()?->id, 'cancelled_at' => now(), 'version' => $locked->version + 1]);
                $this->recordCorrection($locked, 'cancel', $from, $input, $company, $request);
                $this->finishTransition($locked, $from, 'cancelled', 'EVT-PAY-017', $request, $input['reason'] ?? null);
            } elseif ($action === 'void') {
                if (! in_array($from, ['released', 'pending_confirmation'], true)) {
                    throw new RegistryConflictException('Only an unconfirmed released payment can be voided in Phase 8A.');
                }
                if (! trim((string) ($input['reason'] ?? ''))) {
                    throw new RegistryConflictException('A Payment void reason is required.');
                }
                $locked->update(['status' => 'voided', 'failure_reason' => $input['reason'] ?? 'Payment voided.', 'version' => $locked->version + 1]);
                $locked->instruments()->update(['status' => 'voided']);
                $this->recordCorrection($locked, 'void', $from, $input, $company, $request);
                $this->finishTransition($locked, $from, 'voided', 'EVT-PAY-019', $request, $input['reason'] ?? null);
            } else {
                throw new RegistryConflictException('The Payment Instruction action is not supported.', ['action' => $action]);
            }

            return $locked->refresh();
        });

        return $result->load($this->paymentRelations());
    }

    public function allocate(PaymentInstruction $payment, array $input, Company $company, Request $request): PaymentInstruction
    {
        $result = DB::transaction(function () use ($payment, $input, $company, $request) {
            $locked = PaymentInstruction::where('company_id', $company->id)->whereKey($payment->id)->lockForUpdate()->with(['sources', 'confirmations'])->firstOrFail();
            $this->assertVersion($locked->version, $input['version'] ?? null);
            if (! in_array($locked->status, ['confirmed', 'partially_allocated'], true)) {
                throw new RegistryConflictException('Only confirmed payments can be allocated.');
            }
            $amount = $this->decimal($input['amount']);
            $available = bcsub((string) $locked->confirmed_amount, (string) $locked->allocated_amount, 6);
            if (bccomp($amount, $available, 6) > 0) {
                throw new RegistryConflictException('The allocation exceeds the confirmed payment amount.', ['available' => $available]);
            }
            $confirmation = ($input['payment_confirmation_id'] ?? null) ? PaymentConfirmation::where('company_id', $company->id)->where('payment_instruction_id', $locked->id)->where('status', 'confirmed')->whereKey($input['payment_confirmation_id'])->firstOrFail() : $locked->confirmations()->where('status', 'confirmed')->latest()->firstOrFail();
            $source = $input['expense_obligation_id'] ?? null ? $locked->sources()->where('expense_obligation_id', $input['expense_obligation_id'])->lockForUpdate()->first() : (($input['reimbursement_obligation_id'] ?? null) ? $locked->sources()->where('reimbursement_obligation_id', $input['reimbursement_obligation_id'])->lockForUpdate()->first() : $locked->sources()->where('payable_open_item_id', $input['payable_open_item_id'] ?? null)->lockForUpdate()->first());
            if (! $source) {
                throw new RegistryConflictException('The selected payment source was not included in this Payment Instruction.');
            }
            $payable = null;
            $obligation = null;
            $reimbursement = null;
            if ($source->expense_obligation_id) {
                $obligation = ExpenseObligation::where('company_id', $company->id)->whereKey($source->expense_obligation_id)->lockForUpdate()->firstOrFail();
                if ((string) $obligation->payee_id !== (string) $locked->supplier_id || (string) $obligation->currency_id !== (string) $locked->currency_id || ! $obligation->payment_ready) {
                    throw new RegistryConflictException('The Expense Obligation is outside the Payment scope or is no longer payment-ready.');
                }
                if (bccomp($amount, (string) $obligation->remaining_amount, 6) > 0) {
                    throw new RegistryConflictException('The allocation exceeds the Expense Obligation remaining amount.', ['remaining' => (string) $obligation->remaining_amount]);
                }
            } elseif ($source->reimbursement_obligation_id) {
                $reimbursement = ReimbursementObligation::where('company_id', $company->id)->whereKey($source->reimbursement_obligation_id)->lockForUpdate()->firstOrFail();
                if ((string) $reimbursement->payee_id !== (string) $locked->supplier_id || (string) $reimbursement->currency_id !== (string) $locked->currency_id || ! $reimbursement->payment_ready) {
                    throw new RegistryConflictException('The Reimbursement Obligation is outside the Payment scope or is no longer payment-ready.');
                }
                if (bccomp($amount, (string) $reimbursement->remaining_amount, 6) > 0) {
                    throw new RegistryConflictException('The allocation exceeds the Reimbursement Obligation remaining amount.');
                }
            } else {
                $payable = PayableOpenItem::where('company_id', $company->id)->whereKey($source->payable_open_item_id)->lockForUpdate()->with(['supplier', 'currency', 'sourceInvoice'])->firstOrFail();
                if ((string) $payable->supplier_id !== (string) $locked->supplier_id || (string) $payable->currency_id !== (string) $locked->currency_id) {
                    throw new RegistryConflictException('The Payable must belong to the same supplier and currency as the Payment Instruction.');
                }
                if ($payable->hold_status !== 'not_held' || bccomp((string) $payable->remaining_amount, '0', 6) <= 0) {
                    throw new RegistryConflictException('The Payable is not eligible for allocation.');
                }
                if (bccomp($amount, (string) $payable->remaining_amount, 6) > 0) {
                    throw new RegistryConflictException('The allocation exceeds the Payable remaining amount.', ['remaining' => (string) $payable->remaining_amount]);
                }
            }
            $sourceAvailable = bcsub((string) $source->requested_amount, (string) $source->allocated_amount, 6);
            if (bccomp($amount, $sourceAvailable, 6) > 0) {
                throw new RegistryConflictException('The allocation exceeds the selected source amount.', ['available' => $sourceAvailable]);
            }
            $allocation = PaymentAllocation::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $locked->id, 'payment_confirmation_id' => $confirmation->id, 'payable_open_item_id' => $payable?->id, 'expense_obligation_id' => $obligation?->id, 'reimbursement_obligation_id' => $reimbursement?->id, 'company_id' => $company->id, 'currency_id' => $locked->currency_id, 'amount' => $amount, 'allocation_date' => $input['allocation_date'], 'status' => 'applied', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
            if ($payable) {
                PayableEffect::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payable_open_item_id' => $payable->id, 'source_type' => PaymentAllocation::class, 'source_id' => $allocation->id, 'effect_type' => 'payment_allocation', 'amount_delta' => '-'.$amount, 'currency_id' => $locked->currency_id, 'description' => 'Payment allocation '.$locked->payment_number, 'created_by' => $request->user()?->id, 'business_transaction_id' => null, 'accounting_transaction_id' => $locked->accounting_transaction_id]);
                $payable->remaining_amount = bcsub((string) $payable->remaining_amount, $amount, 6);
                $payable->paid_amount = bcadd((string) $payable->paid_amount, $amount, 6);
                $payable->settlement_status = bccomp((string) $payable->remaining_amount, '0', 6) === 0 ? 'settled' : 'partially_paid';
                $payable->version++;
                $payable->last_calculated_at = now();
                $payable->save();
            } elseif ($obligation) {
                $this->expenseSettlement->apply($obligation, $amount, $company, $request);
            } elseif ($reimbursement) {
                $this->reimbursementSettlement->apply($reimbursement, $amount, $company, $request);
            }
            $source->allocated_amount = bcadd((string) $source->allocated_amount, $amount, 6);
            $source->save();
            $locked->allocated_amount = bcadd((string) $locked->allocated_amount, $amount, 6);
            $locked->unapplied_amount = bcsub((string) $locked->confirmed_amount, (string) $locked->allocated_amount, 6);
            $locked->allocation_state = bccomp((string) $locked->unapplied_amount, '0', 6) === 0 ? 'allocated' : 'partially_allocated';
            $locked->status = $locked->allocation_state === 'allocated' ? 'allocated' : 'partially_allocated';
            $locked->version++;
            $locked->save();
            $this->history($locked, 'confirmed', $locked->status, 'EVT-PAY-015', $request, 'Payment allocated.');
            $this->event('EVT-PAY-015', PaymentAllocation::class, $allocation->id, $company, $request);

            return $locked;
        });

        return $result->load($this->paymentRelations());
    }

    public function show(PaymentInstruction $payment, Company $company): PaymentInstruction
    {
        return $payment->load($this->paymentRelations());
    }

    public function timeline(PaymentInstruction $payment, Company $company): array
    {
        return ['status_history' => $payment->statusHistory()->orderBy('created_at')->get(), 'approvals' => $payment->approvals()->orderBy('created_at')->get(), 'attempts' => $payment->attempts()->orderBy('attempt_number')->get(), 'confirmations' => $payment->confirmations()->orderBy('created_at')->get(), 'allocations' => $payment->allocations()->orderBy('created_at')->get()];
    }

    public function remittance(PaymentInstruction $payment, Company $company, Request $request): RemittanceAdvice
    {
        if (! in_array($payment->status, ['confirmed', 'partially_allocated', 'allocated'], true)) {
            throw new RegistryConflictException('A Remittance Advice requires a confirmed Payment.');
        }

        return DB::transaction(function () use ($payment, $company, $request) {
            $advice = RemittanceAdvice::firstOrCreate(['company_id' => $company->id, 'payment_instruction_id' => $payment->id], ['id' => (string) Str::uuid(), 'advice_number' => $this->numbers->next($company->id, 'remittance_advice'), 'status' => 'ready', 'created_by' => $request->user()?->id, 'issued_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id')]);
            $this->event('EVT-PAY-021', RemittanceAdvice::class, $advice->id, $company, $request);

            return $advice;
        });
    }

    private function release(PaymentInstruction $payment, string $from, Company $company, Request $request): void
    {
        if (! in_array($from, ['ready', 'approved', 'scheduled'], true)) {
            throw new RegistryConflictException('Only ready, approved, or due scheduled payments can be released.');
        }
        if ($payment->scheduled_date && $payment->scheduled_date->isFuture()) {
            throw new RegistryConflictException('A scheduled payment cannot be released before its approved date.');
        }
        $this->revalidatePaymentSources($payment, $company);
        if ($payment->instrument_type === 'check' && ! $payment->cashAccount->capabilities()->where('capability', 'ISSUE_CHECK')->where('enabled', true)->exists()) {
            throw new RegistryConflictException('The selected bank Cash Account is not configured to issue checks.');
        }
        $instrument = $payment->instruments()->firstOrCreate(['instrument_type' => $payment->instrument_type], ['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $payment->cash_account_id, 'status' => $payment->instrument_type === 'check' ? 'reserved' : 'issued', 'check_number' => $payment->instrument_type === 'check' ? $this->numbers->next($company->id, 'check') : null, 'release_status' => 'released', 'print_status' => $payment->instrument_type === 'check' ? 'not_printed' : null]);
        $attempt = $this->attempt($payment, $request, $payment->instrument_type === 'cash' ? 'cash' : $payment->instrument_type);
        $payment->update(['status' => 'released', 'execution_state' => 'queued', 'released_by' => $request->user()?->id, 'released_at' => now(), 'version' => $payment->version + 1]);
        $this->history($payment, $from, 'released', 'EVT-PAY-009', $request, 'Payment released to its governed execution channel.');
        $this->event('EVT-PAY-009', PaymentInstruction::class, $payment->id, $company, $request);
    }

    private function confirm(PaymentInstruction $payment, string $from, Company $company, Request $request, array $input): void
    {
        if (! in_array($from, ['released', 'pending_confirmation'], true)) {
            throw new RegistryConflictException('Only released or pending payments can be confirmed.');
        }
        $methodText = strtolower($payment->paymentMethod->method_class.' '.$payment->paymentMethod->code.' '.$payment->paymentMethod->name);
        $isCash = str_contains($methodText, 'cash');
        if ($isCash && (empty($input['recipient_acknowledgement']) || empty($input['evidence_reference']))) {
            throw new RegistryConflictException('Cash Payment confirmation requires recipient acknowledgement and evidence.');
        }
        if (! $isCash && empty($input['external_reference'])) {
            throw new RegistryConflictException('Electronic or check Payment confirmation requires an external or instrument reference.');
        }
        if (! $isCash && (empty($input['evidence_reference']) || empty($input['reason']))) {
            throw new RegistryConflictException('Manual electronic or check confirmation requires a reason and evidence reference.');
        }
        $confirmedAmount = $this->decimal($input['confirmed_amount'] ?? $payment->net_amount);
        if (bccomp($confirmedAmount, '0', 6) <= 0 || bccomp($confirmedAmount, (string) $payment->net_amount, 6) > 0) {
            throw new RegistryConflictException('The confirmed amount must be positive and cannot exceed the authorized payment amount.');
        }
        $confirmedDate = $input['confirmed_date'] ?? now()->toDateString();
        $attempt = $payment->attempts()->latest('attempt_number')->lockForUpdate()->first() ?? $this->attempt($payment, $request, $isCash ? 'cash' : 'electronic');
        $offset = $this->paymentOffsetAccount($payment, $company);
        $cashEffect = $this->cashMovements->createPaymentEffect($company, $payment->cashAccount, $confirmedAmount, $payment->currency->code, $confirmedDate, PaymentInstruction::class, $payment->id, $payment->payment_number, $offset->id, $isCash ? 'EVT-PAY-010' : 'EVT-PAY-011', $isCash || $payment->paymentMethod->clearing_behavior === 'direct' ? 'not_applicable' : 'pending', $request);
        $confirmation = PaymentConfirmation::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $payment->id, 'payment_execution_attempt_id' => $attempt->id, 'company_id' => $company->id, 'status' => 'confirmed', 'confirmed_amount' => $confirmedAmount, 'confirmed_date' => $confirmedDate, 'external_reference' => $input['external_reference'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? null, 'reason' => $input['recipient_acknowledgement'] ?? $input['reason'] ?? null, 'manual_confirmation' => true, 'confirmed_by' => $request->user()?->id, 'confirmed_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id')]);
        $attempt->update(['status' => 'succeeded', 'external_reference' => $input['external_reference'] ?? null, 'finished_at' => now()]);
        $payment->update(['status' => 'confirmed', 'execution_state' => 'succeeded', 'confirmation_state' => 'confirmed', 'confirmed_amount' => $confirmedAmount, 'unapplied_amount' => $confirmedAmount, 'external_reference' => $input['external_reference'] ?? $payment->external_reference, 'confirmed_by' => $request->user()?->id, 'confirmed_at' => now(), 'cash_movement_id' => $cashEffect['movement']->id, 'accounting_transaction_id' => $cashEffect['accounting']->id, 'version' => $payment->version + 1]);
        if ($payment->source_kind === 'supplier_advance') {
            PaymentAdvance::where('company_id', $company->id)->where('payment_instruction_id', $payment->id)->update(['available_amount' => $confirmedAmount, 'status' => 'available']);
        }
        $this->history($payment, $from, 'confirmed', $isCash ? 'EVT-PAY-010' : 'EVT-PAY-011', $request, 'Payment confirmation recorded with an MDS-700 Cash Movement.');
        $this->event($isCash ? 'EVT-PAY-010' : 'EVT-PAY-011', PaymentConfirmation::class, $confirmation->id, $company, $request);
    }

    private function validateSources(string $supplierId, string $currencyId, ?string $branchId, array $sourceInputs, Company $company): array
    {
        $supplier = BusinessPartner::where('company_id', $company->id)->whereKey($supplierId)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'supplier')->where('status', 'active'))->first();
        if (! $supplier) {
            throw new RegistryConflictException('An active same-company Supplier is required.', ['dependency' => 'supplier']);
        }
        $currency = ReferenceCurrency::where('company_id', $company->id)->whereKey($currencyId)->where('status', 'active')->first();
        if (! $currency) {
            throw new RegistryConflictException('An active same-company payment Currency is required.', ['dependency' => 'currency']);
        }
        $branch = $branchId ? DB::table('branches')->where('company_id', $company->id)->where('status', 'active')->where('id', $branchId)->first() : null;
        if ($branchId && ! $branch) {
            throw new RegistryConflictException('The Branch must be active and belong to the current company.');
        }
        $ids = collect($sourceInputs)->pluck('payable_open_item_id')->map(fn ($id) => (string) $id);
        if ($ids->count() !== $ids->unique()->count()) {
            throw new RegistryConflictException('A Payable may appear only once in a Payment Instruction.');
        }
        $payables = PayableOpenItem::where('company_id', $company->id)->whereIn('id', $ids->all())->with(['supplier', 'currency', 'sourceInvoice', 'branch'])->lockForUpdate()->get()->keyBy(fn ($item) => (string) $item->id);
        if ($payables->count() !== $ids->count()) {
            throw new RegistryConflictException('Every selected Payable must belong to the current company.');
        }
        $sources = [];
        $total = '0';
        foreach ($sourceInputs as $sourceInput) {
            $payable = $payables->get((string) $sourceInput['payable_open_item_id']);
            $amount = $this->decimal($sourceInput['amount']);
            if ((string) $payable->supplier_id !== (string) $supplier->id || (string) $payable->currency_id !== (string) $currency->id) {
                throw new RegistryConflictException('All Payment sources must use the same Supplier and Currency.');
            }
            if ($branchId && (string) $payable->branch_id !== (string) $branchId) {
                throw new RegistryConflictException('All Payment sources must belong to the selected Branch.');
            }
            if ($payable->hold_status !== 'not_held' || $payable->settlement_status === 'settled' || bccomp((string) $payable->remaining_amount, '0', 6) <= 0) {
                throw new RegistryConflictException('A selected Payable is held, settled, or no longer payment-ready.', ['payable_id' => $payable->id]);
            }
            if (! $payable->sourceInvoice || $payable->sourceInvoice->status !== 'posted') {
                throw new RegistryConflictException('A selected Payable must have a posted Supplier Invoice source.');
            }
            if (bccomp($amount, (string) $payable->remaining_amount, 6) > 0) {
                throw new RegistryConflictException('A Payment source amount exceeds the Payable remaining amount.', ['payable_id' => $payable->id, 'remaining' => (string) $payable->remaining_amount]);
            }
            $sources[] = ['payable' => $payable, 'amount' => $amount];
            $total = bcadd($total, $amount, 6);
        }

        return [$supplier, $currency, $branch, $sources, $total];
    }

    private function revalidatePaymentSources(PaymentInstruction $payment, Company $company): void
    {
        foreach ($payment->sources as $source) {
            if ($source->expense_obligation_id) {
                $obligation = ExpenseObligation::where('company_id', $company->id)->whereKey($source->expense_obligation_id)->lockForUpdate()->firstOrFail();
                if (! $obligation->payment_ready || bccomp((string) $obligation->remaining_amount, (string) $source->requested_amount, 6) < 0) {
                    throw new RegistryConflictException('An Expense Payment source is no longer eligible; refresh the Payment Instruction.', ['expense_obligation_id' => $obligation->id]);
                }

                continue;
            }
            if ($source->reimbursement_obligation_id) {
                $obligation = ReimbursementObligation::where('company_id', $company->id)->whereKey($source->reimbursement_obligation_id)->lockForUpdate()->firstOrFail();
                if (! $obligation->payment_ready || bccomp((string) $obligation->remaining_amount, (string) $source->requested_amount, 6) < 0) {
                    throw new RegistryConflictException('A Reimbursement Payment source is no longer eligible; refresh the Payment Instruction.', ['reimbursement_obligation_id' => $obligation->id]);
                }

                continue;
            }
            $payable = PayableOpenItem::where('company_id', $company->id)->whereKey($source->payable_open_item_id)->lockForUpdate()->firstOrFail();
            $available = (string) $payable->remaining_amount;
            if ($payable->hold_status !== 'not_held' || bccomp($available, (string) $source->requested_amount, 6) < 0) {
                throw new RegistryConflictException('A Payment source is no longer eligible; refresh the Payment Instruction.', ['payable_id' => $payable->id]);
            }
        }
    }

    private function paymentMethod(string $id, Company $company, string $date): PaymentMethod
    {
        $method = PaymentMethod::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->where('supports_outgoing', true)->first();
        if (! $method || ($method->effective_from && $date < $method->effective_from->toDateString()) || ($method->effective_to && $date > $method->effective_to->toDateString())) {
            throw new RegistryConflictException('The selected Payment Method is inactive or not effective for the payment date.');
        }

        return $method;
    }

    private function cashAccount(string $id, Company $company, string $currencyId, ?string $branchId, PaymentMethod $method): CashAccount
    {
        $account = CashAccount::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->with(['currency', 'capabilities'])->first();
        if (! $account || ! $account->capabilities->contains(fn ($capability) => $capability->capability === 'MAKE_PAYMENTS' && $capability->enabled)) {
            throw new RegistryConflictException('The selected Cash Account is not active or is not configured for outgoing payments.');
        }
        if ((string) $account->currency_id !== (string) $currencyId) {
            throw new RegistryConflictException('The Cash Account currency must match the Payment currency.');
        }
        if ($branchId && (string) $account->branch_id !== (string) $branchId) {
            throw new RegistryConflictException('The Cash Account must belong to the selected Branch.');
        }
        if ($method->requires_account_selection !== true) {
            return $account;
        }

        return $account;
    }

    private function paymentOffsetAccount(PaymentInstruction $payment, Company $company): AccountTitle
    {
        if ($payment->source_kind === 'supplier_advance') {
            $account = AccountTitle::where('company_id', $company->id)->where('status', 'active')->where('posting_eligible', true)->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%supplier advance%'])->orWhereRaw('LOWER(name) LIKE ?', ['%advance to supplier%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%advance%']))->first();
            if (! $account) {
                throw new RegistryConflictException('An active posting Supplier Advances Account Title is required for a Supplier Advance.');
            }

            return $account;
        }

        return $this->payableAccount($company);
    }

    private function payableAccount(Company $company): AccountTitle
    {
        $account = AccountTitle::where('company_id', $company->id)->where('classification', 'liability')->where('status', 'active')->where('posting_eligible', true)->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%payable%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%payable%'])->orWhereRaw('LOWER(code) LIKE ?', ['%payable%']))->first();
        if (! $account) {
            throw new RegistryConflictException('An active posting Accounts Payable Account Title is required for supplier payment.');
        }

        return $account;
    }

    private function duplicateRisk(Company $company, string $supplierId, string $currencyId, string $amount, string $date, ?string $reference, ?Request $request = null, bool $override = false, ?string $overrideReason = null): void
    {
        if (! $reference) {
            return;
        }
        $duplicate = PaymentInstruction::where('company_id', $company->id)->where('supplier_id', $supplierId)->where('currency_id', $currencyId)->where('gross_amount', $amount)->whereDate('payment_date', $date)->where('reference', $reference)->whereNotIn('status', ['cancelled', 'rejected', 'failed', 'voided'])->exists();
        if ($duplicate && (! $override || ! $request?->user()?->hasPermission('payments.duplicates.review', $company->id) || ! trim((string) $overrideReason))) {
            throw new RegistryConflictException('A possible duplicate Payment Instruction already uses this supplier, amount, date, and reference.', ['dependency' => 'duplicate_payment']);
        }
    }

    private function instrumentType(PaymentMethod $method): string
    {
        $text = strtolower($method->method_class.' '.$method->code.' '.$method->name);

        return str_contains($text, 'check') ? 'check' : (str_contains($text, 'cash') ? 'cash' : 'electronic');
    }

    private function attempt(PaymentInstruction $payment, Request $request, string $channel): PaymentExecutionAttempt
    {
        $number = ((int) $payment->attempts()->max('attempt_number')) + 1;

        return PaymentExecutionAttempt::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $payment->id, 'payment_instrument_id' => $payment->instruments()->latest()->value('id'), 'company_id' => $payment->company_id, 'attempt_number' => $number, 'channel' => $channel, 'status' => 'queued', 'started_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
    }

    private function finishTransition(PaymentInstruction $payment, string $from, string $to, string $event, Request $request, ?string $reason): void
    {
        $this->history($payment, $from, $to, $event, $request, $reason);
        $this->event($event, PaymentInstruction::class, $payment->id, Company::findOrFail($payment->company_id), $request);
    }

    private function history(PaymentInstruction $payment, ?string $from, string $to, string $event, Request $request, ?string $reason): void
    {
        PaymentStatusHistory::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $payment->id, 'company_id' => $payment->company_id, 'from_status' => $from, 'to_status' => $to, 'event_code' => $event, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $payment->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function historyRequest(PaymentRequest $document, ?string $from, string $to, string $event, Request $request, ?string $reason): void
    {
        PaymentRequestStatusHistory::create(['id' => (string) Str::uuid(), 'payment_request_id' => $document->id, 'company_id' => $document->company_id, 'from_status' => $from, 'to_status' => $to, 'event_code' => $event, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $document->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function event(string $event, string $type, string $id, Company $company, Request $request): void
    {
        Event::dispatch(new PaymentLifecycleEvent($event, $company->id, $type, $id, $request->user()?->id, $request->attributes->get('correlation_id')));
    }

    private function assertVersion(int $actual, ?int $expected): void
    {
        if ($expected !== null && $actual !== $expected) {
            throw new RegistryConflictException('The Payment record changed. Refresh before trying again.', ['expected_version' => $expected, 'actual_version' => $actual]);
        }
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value) || bccomp((string) $value, '0', 6) <= 0) {
            throw new RegistryConflictException('The amount must be greater than zero.');
        }

        return bcadd((string) $value, '0', 6);
    }

    private function paymentRelations(): array
    {
        return ['supplier', 'branch', 'currency', 'paymentMethod', 'cashAccount.currency', 'sources.payable', 'sources.expenseObligation.expense', 'sources.reimbursementObligation.claim', 'approvals', 'instruments', 'attempts', 'confirmations', 'allocations', 'statusHistory', 'remittanceAdvice', 'advance', 'corrections', 'batchItems.batch', 'voucher'];
    }

    private function recordCorrection(PaymentInstruction $payment, string $type, string $originalStatus, array $input, Company $company, Request $request): void
    {
        $correction = PaymentCorrection::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'original_payment_id' => $payment->id, 'correction_number' => $this->numbers->next($company->id, 'payment_correction'), 'correction_type' => $type, 'status' => 'completed', 'original_status' => $originalStatus, 'amount' => $payment->net_amount, 'reason' => $input['reason'], 'evidence_reference' => $input['evidence_reference'] ?? null, 'created_by' => $request->user()?->id, 'completed_by' => $request->user()?->id, 'completed_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
        $this->audit->record($request, 'payment.'.$type, $correction, $company->id, ['payment_id' => $payment->id, 'status' => $originalStatus], ['payment_id' => $payment->id, 'status' => $payment->status], $input['reason'], 'Payment correction recorded', 'A non-posted Payment correction preserved the original lifecycle history.');
    }
}
