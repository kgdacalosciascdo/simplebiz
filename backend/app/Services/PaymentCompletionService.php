<?php

namespace App\Services;

use App\Events\PaymentLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountingTransaction;
use App\Models\AccountingTransactionLine;
use App\Models\AccountTitle;
use App\Models\BusinessTransaction;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\ExpenseObligation;
use App\Models\PayableEffect;
use App\Models\PayableOpenItem;
use App\Models\PaymentAdvance;
use App\Models\PaymentAllocation;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\PaymentBatchStatusHistory;
use App\Models\PaymentCorrection;
use App\Models\PaymentExecutionAttempt;
use App\Models\PaymentInstruction;
use App\Models\PaymentInstrument;
use App\Models\PaymentStatusHistory;
use App\Models\PaymentVoucher;
use App\Models\ReimbursementObligation;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class PaymentCompletionService
{
    public function __construct(private readonly PaymentService $payments, private readonly CashMovementService $cashMovements, private readonly CashDocumentNumberService $numbers, private readonly AuditService $audit, private readonly ExpenseSettlementService $expenseSettlement, private readonly ReimbursementSettlementService $reimbursementSettlement) {}

    public function reverse(PaymentInstruction $payment, array $input, Company $company, Request $request): PaymentInstruction
    {
        $this->requireReason($input['reason'] ?? null, 'A Payment reversal reason is required.');
        $result = DB::transaction(function () use ($payment, $input, $company, $request) {
            $locked = $this->lockedPayment($payment, $company);
            $fromStatus = $locked->status;
            if (! in_array($locked->status, ['confirmed', 'partially_allocated', 'allocated'], true) || ! $locked->cash_movement_id) {
                throw new RegistryConflictException('Only a confirmed Payment with a Cash Movement can be reversed.');
            }
            if ($locked->corrections()->where('correction_type', 'reversal')->where('status', 'completed')->exists()) {
                throw new RegistryConflictException('This Payment has already been reversed.');
            }
            $correction = PaymentCorrection::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'original_payment_id' => $locked->id, 'correction_number' => $this->numbers->next($company->id, 'payment_correction'), 'correction_type' => 'reversal', 'status' => 'processing', 'original_status' => $locked->status, 'amount' => $locked->confirmed_amount, 'reason' => $input['reason'], 'evidence_reference' => $input['evidence_reference'] ?? null, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $movement = CashMovement::where('company_id', $company->id)->whereKey($locked->cash_movement_id)->with('account')->firstOrFail();
            $offsetId = $this->cashOffsetForMovement($movement, $company);
            $cash = $this->cashMovements->reversePaymentEffect($company, $movement, $offsetId, $input['correction_date'] ?? now()->toDateString(), $correction, $request);
            foreach ($locked->allocations()->where('status', 'applied')->lockForUpdate()->get() as $allocation) {
                $this->unapplyLocked($locked, $allocation, $company, $request, 'reversal', $input['reason']);
            }
            $locked->update(['status' => 'reversed', 'execution_state' => 'reversed', 'confirmation_state' => 'reversed', 'allocation_state' => 'reversed', 'reversal_reason' => $input['reason'], 'reversal_correction_id' => $correction->id, 'unapplied_amount' => '0', 'version' => $locked->version + 1]);
            $correction->update(['status' => 'completed', 'reversal_cash_movement_id' => $cash['movement']->id, 'accounting_transaction_id' => $cash['accounting']->id, 'completed_by' => $request->user()?->id, 'completed_at' => now()]);
            $this->paymentHistory($locked, $fromStatus, 'reversed', 'EVT-PAY-020', $request, $input['reason']);
            $this->audit->record($request, 'payment.reversed', $correction, $company->id, ['payment_id' => $locked->id, 'status' => $fromStatus], ['payment_id' => $locked->id, 'status' => 'reversed', 'cash_reversal_movement_id' => $cash['movement']->id], $input['reason'], 'Payment reversed', 'A confirmed Payment was reversed through a linked cash counter-movement and source restoration.');
            $this->event('EVT-PAY-020', PaymentCorrection::class, $correction->id, $company, $request);

            return $locked;
        });

        return $result->load($this->relations());
    }

    public function unapply(PaymentInstruction $payment, PaymentAllocation $allocation, array $input, Company $company, Request $request): PaymentInstruction
    {
        $this->requireReason($input['reason'] ?? null, 'An allocation unapplication reason is required.');

        return DB::transaction(function () use ($payment, $allocation, $input, $company, $request) {
            $locked = $this->lockedPayment($payment, $company);
            $fromStatus = $locked->status;
            $original = PaymentAllocation::where('company_id', $company->id)->where('payment_instruction_id', $locked->id)->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $this->unapplyLocked($locked, $original, $company, $request, 'unapplication', $input['reason']);
            $this->paymentHistory($locked, $fromStatus, $locked->status, 'EVT-PAY-016', $request, $input['reason']);

            return $locked->refresh()->load($this->relations());
        });
    }

    public function reallocate(PaymentInstruction $payment, PaymentAllocation $allocation, array $input, Company $company, Request $request): PaymentInstruction
    {
        $this->requireReason($input['reason'] ?? null, 'A Payment reallocation reason is required.');

        if ($allocation->expense_obligation_id || $allocation->reimbursement_obligation_id) {
            throw new RegistryConflictException('Expense payment allocations are owned by the Expense Obligation and cannot be reallocated through the supplier-payable reallocation workflow.');
        }

        return DB::transaction(function () use ($payment, $allocation, $input, $company, $request) {
            $locked = $this->lockedPayment($payment, $company);
            $fromStatus = $locked->status;
            $original = PaymentAllocation::where('company_id', $company->id)->where('payment_instruction_id', $locked->id)->whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $amount = $this->decimal($input['amount'] ?? $original->amount);
            if (bccomp($amount, (string) $original->amount, 6) > 0) {
                throw new RegistryConflictException('A reallocation cannot exceed the original allocation amount.');
            }
            $this->unapplyLocked($locked, $original, $company, $request, 'reallocation', $input['reason']);
            $this->applyToPayableLocked($locked, $input['payable_open_item_id'], $amount, $company, $request, 'reallocation', $input['reason']);
            $this->paymentHistory($locked, $fromStatus, $locked->status, 'EVT-PAY-016', $request, $input['reason']);

            return $locked->refresh()->load($this->relations());
        });
    }

    public function advances(Company $company, Request $request): array
    {
        $query = PaymentAdvance::where('company_id', $company->id)->with(['supplier', 'currency', 'payment'])->where('available_amount', '>', 0)->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->string('supplier_id')))->when($request->filled('currency_id'), fn ($q) => $q->where('currency_id', $request->string('currency_id')))->orderByDesc('created_at');
        $paginator = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return [$paginator->items(), ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function applyAdvance(PaymentAdvance $advance, array $input, Company $company, Request $request): PaymentInstruction
    {
        $this->requireReason($input['reason'] ?? null, 'An advance application reason is required.');

        return DB::transaction(function () use ($advance, $input, $company, $request) {
            $advance = PaymentAdvance::where('company_id', $company->id)->whereKey($advance->id)->lockForUpdate()->firstOrFail();
            $payment = $this->lockedPayment(PaymentInstruction::findOrFail($advance->payment_instruction_id), $company);
            $amount = $this->decimal($input['amount']);
            if (bccomp($amount, (string) $advance->available_amount, 6) > 0) {
                throw new RegistryConflictException('The Supplier Advance available amount is insufficient.');
            }
            $this->applyToPayableLocked($payment, $input['payable_open_item_id'], $amount, $company, $request, 'advance_application', $input['reason'], $advance);

            return $payment->refresh()->load($this->relations());
        });
    }

    public function checks(Company $company, Request $request): array
    {
        $query = PaymentInstrument::where('company_id', $company->id)->where('instrument_type', 'check')->with(['payment.supplier', 'payment.currency', 'payment.cashMovement', 'cashAccount'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('q'), fn ($q) => $q->where('check_number', 'like', '%'.$request->string('q').'%'))->orderByDesc('created_at');
        $paginator = $query->paginate(min((int) $request->integer('per_page', 25), 100));

        return [$paginator->items(), ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function checkAction(PaymentInstrument $instrument, string $action, array $input, Company $company, Request $request): PaymentInstrument
    {
        $result = DB::transaction(function () use ($instrument, $action, $input, $company, $request) {
            $locked = PaymentInstrument::where('company_id', $company->id)->whereKey($instrument->id)->lockForUpdate()->with('payment')->firstOrFail();
            $payment = PaymentInstruction::where('company_id', $company->id)->whereKey($locked->payment_instruction_id)->lockForUpdate()->firstOrFail();
            if ($locked->instrument_type !== 'check') {
                throw new RegistryConflictException('Only check instruments support check controls.');
            }
            if (in_array($action, ['stop', 'void', 'replace'], true)) {
                $this->requireReason($input['reason'] ?? null, 'A reason is required for this check control.');
            }
            if ($action === 'print') {
                if ($locked->status !== 'reserved') {
                    throw new RegistryConflictException('Only a reserved check can be printed.');
                }
                $locked->update(['status' => 'printed', 'print_status' => 'printed', 'printed_by' => $request->user()?->id, 'printed_at' => now()]);
            } elseif ($action === 'sign') {
                if (! in_array($locked->status, ['printed', 'reserved'], true)) {
                    throw new RegistryConflictException('Only a printed check can be signed.');
                }
                $locked->update(['status' => 'signed', 'signed_by' => $request->user()?->id, 'signed_at' => now()]);
            } elseif ($action === 'release') {
                if (! in_array($locked->status, ['reserved', 'printed', 'signed'], true)) {
                    throw new RegistryConflictException('This check cannot be released from its current status.');
                }
                $locked->update(['status' => 'released', 'release_status' => 'released']);
            } elseif ($action === 'void') {
                if ($payment->cash_movement_id) {
                    throw new RegistryConflictException('A check with a Cash Movement must use Payment reversal; it cannot be voided directly.');
                }
                if (in_array($locked->status, ['voided', 'stopped', 'stale'], true)) {
                    throw new RegistryConflictException('This check has already reached a terminal control state.');
                }
                $locked->update(['status' => 'voided', 'void_reason' => $input['reason'], 'voided_by' => $request->user()?->id, 'voided_at' => now(), 'release_status' => 'voided']);
                if (in_array($payment->status, ['released', 'pending_confirmation'], true)) {
                    $from = $payment->status;
                    $payment->update(['status' => 'voided', 'failure_reason' => $input['reason'], 'version' => $payment->version + 1]);
                    $this->paymentHistory($payment, $from, 'voided', 'EVT-PAY-019', $request, $input['reason']);
                }
            } elseif ($action === 'stop') {
                if (in_array($locked->status, ['voided', 'stale', 'stopped'], true)) {
                    throw new RegistryConflictException('This check cannot be stopped from its current status.');
                }
                if (! trim((string) ($input['evidence_reference'] ?? ''))) {
                    throw new RegistryConflictException('Check stop evidence is required.');
                }
                $locked->update(['status' => 'stopped', 'stopped_by' => $request->user()?->id, 'stopped_at' => now(), 'stop_reason' => $input['reason'], 'stop_evidence_reference' => $input['evidence_reference']]);
            } elseif ($action === 'stale') {
                $days = max(1, (int) config('payments.check_stale_days', 180));
                if ($payment->payment_date?->diffInDays(now()) < $days) {
                    throw new RegistryConflictException('This check has not reached its server-authoritative stale date.', ['stale_days' => $days]);
                }
                if (in_array($locked->status, ['voided', 'stopped', 'stale'], true)) {
                    throw new RegistryConflictException('This check is already in a terminal control state.');
                }
                $locked->update(['status' => 'stale', 'stale_at' => now()]);
            } elseif ($action === 'replace') {
                if (! in_array($locked->status, ['voided', 'stopped', 'stale'], true) || $locked->replaced_by_instrument_id) {
                    throw new RegistryConflictException('Only a stopped, voided, or stale check without a replacement can be replaced.');
                }
                $replacement = PaymentInstrument::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $payment->id, 'company_id' => $company->id, 'cash_account_id' => $locked->cash_account_id, 'instrument_type' => 'check', 'status' => 'reserved', 'check_number' => $this->numbers->next($company->id, 'check'), 'release_status' => 'not_released', 'print_status' => 'not_printed', 'replaces_instrument_id' => $locked->id]);
                $locked->update(['replaced_by_instrument_id' => $replacement->id]);
            } else {
                throw new RegistryConflictException('The check control action is not supported.');
            }
            $this->audit->record($request, 'payment.check.'.$action, $locked, $company->id, [], ['status' => $locked->status, 'check_number' => $locked->check_number], $input['reason'] ?? null, 'Check control recorded', 'A governed check-register action was recorded.');

            return $locked->refresh();
        });

        return $result->load(['payment.supplier', 'payment.currency', 'payment.cashMovement', 'cashAccount']);
    }

    public function recover(PaymentInstruction $payment, array $input, Company $company, Request $request): PaymentInstruction
    {
        $this->requireReason($input['reason'] ?? null, 'A payment recovery reason is required.');
        if (! trim((string) ($input['evidence_reference'] ?? ''))) {
            throw new RegistryConflictException('Payment recovery evidence is required.');
        }
        $result = DB::transaction(function () use ($payment, $input, $company, $request) {
            $locked = $this->lockedPayment($payment, $company);
            $fromStatus = $locked->status;
            if (! in_array($locked->status, ['pending_confirmation', 'failed'], true) || $locked->cash_movement_id) {
                throw new RegistryConflictException('Only an unresolved payment without a Cash Movement can enter recovery.');
            }
            $latest = $locked->attempts()->latest('attempt_number')->lockForUpdate()->first();
            $resolution = $input['resolution'] ?? null;
            if ($resolution === 'retry') {
                if (! $latest || ! in_array($latest->status, ['pending', 'failed', 'timed_out'], true)) {
                    throw new RegistryConflictException('Only a pending or failed execution attempt can be retried.');
                }
                if ($locked->attempts()->where('status', 'succeeded')->exists()) {
                    throw new RegistryConflictException('A successful execution attempt prevents another retry.');
                }
                $latest->update(['status' => 'recovered', 'response_message' => $input['reason'], 'finished_at' => now()]);
                $attempt = PaymentExecutionAttempt::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $locked->id, 'payment_instrument_id' => $locked->instruments()->latest()->value('id'), 'company_id' => $company->id, 'attempt_number' => ((int) $locked->attempts()->max('attempt_number')) + 1, 'channel' => $locked->instrument_type === 'cash' ? 'cash' : $locked->instrument_type, 'status' => 'queued', 'started_at' => now(), 'response_message' => 'Controlled retry after recovery review.', 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
                $locked->update(['status' => 'released', 'execution_state' => 'queued', 'confirmation_state' => 'pending', 'failure_reason' => null, 'version' => $locked->version + 1]);
                $this->paymentHistory($locked, $fromStatus, 'released', 'EVT-PAY-014', $request, $input['reason']);
            } elseif (in_array($resolution, ['failed', 'rejected'], true)) {
                if ($latest) {
                    $latest->update(['status' => $resolution === 'failed' ? 'failed' : 'duplicate', 'response_message' => $input['reason'], 'finished_at' => now()]);
                }
                $status = $resolution === 'failed' ? 'failed' : 'rejected';
                $locked->update(['status' => $status, 'execution_state' => 'failed', 'confirmation_state' => $status, 'failure_reason' => $input['reason'], 'version' => $locked->version + 1]);
                $this->paymentHistory($locked, $fromStatus, $status, 'EVT-PAY-013', $request, $input['reason']);
            } else {
                throw new RegistryConflictException('Recovery resolution must be retry, failed, or rejected.');
            }
            $this->audit->record($request, 'payment.recovery.'.$resolution, $locked, $company->id, [], ['status' => $locked->status], $input['reason'], 'Payment recovery recorded', 'A controlled payment execution recovery decision was recorded.');

            return $locked;
        });

        return $result->load($this->relations());
    }

    public function batches(Company $company, Request $request): array
    {
        $paginator = PaymentBatch::where('company_id', $company->id)->withCount('items')->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 20), 100));

        return [$paginator->items(), ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function createBatch(array $input, Company $company, Request $request): PaymentBatch
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $payments = PaymentInstruction::where('company_id', $company->id)->whereIn('id', $input['payment_ids'])->lockForUpdate()->with(['paymentMethod', 'cashAccount', 'currency'])->get();
            if ($payments->count() !== count(array_unique($input['payment_ids']))) {
                throw new RegistryConflictException('Every Payment in a batch must belong to the current company.');
            }
            $this->assertBatchCompatibility($payments);
            $eligible = ['ready', 'scheduled'];
            if ($payments->contains(fn ($payment) => ! in_array($payment->status, $eligible, true) || ($payment->scheduled_date && $payment->scheduled_date->isFuture()))) {
                throw new RegistryConflictException('Only due, approved Payment Instructions can be batched.');
            }
            $first = $payments->first();
            $controlTotal = '0';
            foreach ($payments as $payment) {
                $controlTotal = bcadd($controlTotal, (string) $payment->net_amount, 6);
            }
            $batch = PaymentBatch::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'batch_number' => $this->numbers->next($company->id, 'payment_batch'), 'name' => $input['name'] ?? null, 'status' => 'draft', 'payment_method_id' => $first->payment_method_id, 'cash_account_id' => $first->cash_account_id, 'currency_id' => $first->currency_id, 'control_total' => $controlTotal, 'item_count' => $payments->count(), 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($payments as $payment) {
                PaymentBatchItem::create(['id' => (string) Str::uuid(), 'payment_batch_id' => $batch->id, 'company_id' => $company->id, 'payment_instruction_id' => $payment->id, 'status' => 'queued']);
            }
            $this->batchHistory($batch, null, 'draft', $request);

            return $batch->load(['items.payment', 'statusHistory']);
        });
    }

    public function batchAction(PaymentBatch $batch, string $action, array $input, Company $company, Request $request): PaymentBatch
    {
        $result = DB::transaction(function () use ($batch, $action, $input, $company, $request) {
            $locked = PaymentBatch::where('company_id', $company->id)->whereKey($batch->id)->lockForUpdate()->with('items.payment')->firstOrFail();
            $from = $locked->status;
            if ($action === 'validate') {
                if ($from !== 'draft') {
                    throw new RegistryConflictException('Only a draft Payment Batch can be validated.');
                }
                $this->revalidateBatch($locked, $company);
                $locked->update(['status' => 'validated', 'version' => $locked->version + 1]);
            } elseif ($action === 'submit') {
                if (! in_array($from, ['draft', 'validated'], true) || ! $locked->items()->exists()) {
                    throw new RegistryConflictException('Only a non-empty draft batch can be submitted.');
                }
                $this->revalidateBatch($locked, $company);
                $locked->update(['status' => 'pending_approval', 'submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'version' => $locked->version + 1]);
            } elseif ($action === 'approve') {
                if ($from !== 'pending_approval' || (int) $locked->prepared_by === (int) $request->user()?->id) {
                    throw new RegistryConflictException('Only a pending batch can be approved by a different user.');
                }
                $this->revalidateBatch($locked, $company);
                $locked->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $locked->version + 1]);
            } elseif ($action === 'generate') {
                if ($from !== 'approved') {
                    throw new RegistryConflictException('Only an approved Payment Batch can be generated.');
                }
                $this->revalidateBatch($locked, $company);
                $locked->update(['status' => 'generated', 'generated_by' => $request->user()?->id, 'generated_at' => now(), 'version' => $locked->version + 1]);
            } elseif ($action === 'release' || $action === 'execute') {
                $allowed = $action === 'release' ? ['approved', 'generated'] : ['released', 'partially_completed', 'failed'];
                if (! in_array($from, $allowed, true)) {
                    throw new RegistryConflictException('The Payment Batch is not ready for execution.');
                }
                $this->revalidateBatch($locked, $company, true);
                $locked->update(['status' => 'released', 'released_by' => $request->user()?->id, 'released_at' => now(), 'version' => $locked->version + 1]);
                foreach ($locked->items()->whereIn('status', ['queued', 'failed'])->lockForUpdate()->get() as $item) {
                    $payment = PaymentInstruction::where('company_id', $company->id)->whereKey($item->payment_instruction_id)->firstOrFail();
                    try {
                        if (in_array($payment->status, ['approved', 'ready', 'scheduled'], true)) {
                            $payment = $this->payments->transitionPayment($payment, 'release', $company, $request, ['version' => $payment->version]);
                        }
                        if ($payment->status === 'released') {
                            $item->update(['status' => 'succeeded', 'failure_reason' => null, 'processed_at' => now()]);
                        } else {
                            $item->update(['status' => 'failed', 'failure_reason' => 'Payment did not reach the released execution state.', 'processed_at' => now()]);
                        }
                    } catch (\Throwable $exception) {
                        $item->update(['status' => 'failed', 'failure_reason' => $exception instanceof RegistryConflictException ? $exception->getMessage() : 'Payment batch item failed safely.', 'processed_at' => now()]);
                    }
                }
                $succeeded = $locked->items()->where('status', 'succeeded')->count();
                $failed = $locked->items()->where('status', 'failed')->count();
                $status = $failed === 0 ? 'completed' : ($succeeded === 0 ? 'failed' : 'partially_completed');
                $locked->update(['status' => $status, 'succeeded_count' => $succeeded, 'failed_count' => $failed, 'version' => $locked->version + 1]);
            } elseif ($action === 'close') {
                if (! in_array($from, ['completed', 'failed', 'partially_completed'], true) || $locked->items()->where('status', 'queued')->exists()) {
                    throw new RegistryConflictException('A Payment Batch can close only after every item has a final governed disposition.');
                }
                $locked->update(['status' => 'closed', 'closed_by' => $request->user()?->id, 'closed_at' => now(), 'version' => $locked->version + 1]);
            } elseif ($action === 'cancel') {
                if (! in_array($from, ['draft', 'validated', 'pending_approval', 'approved'], true)) {
                    throw new RegistryConflictException('Only unreleased batches can be cancelled.');
                }
                $this->requireReason($input['reason'] ?? null, 'A batch cancellation reason is required.');
                $locked->update(['status' => 'cancelled', 'reason' => $input['reason'], 'version' => $locked->version + 1]);
            } else {
                throw new RegistryConflictException('The Payment Batch action is not supported.');
            }
            $this->batchHistory($locked, $from, $locked->status, $request, $input['reason'] ?? null);
            $this->audit->record($request, 'payment.batch.'.$action, $locked, $company->id, [], ['status' => $locked->status, 'succeeded_count' => $locked->succeeded_count, 'failed_count' => $locked->failed_count], $input['reason'] ?? null, 'Payment Batch action recorded', 'A Payment Batch lifecycle action was recorded with item-level outcomes.');

            return $locked->refresh();
        });

        return $result->load(['items.payment.supplier', 'items.payment.currency', 'statusHistory']);
    }

    public function batchItems(PaymentBatch $batch, array $input, Company $company, Request $request): PaymentBatch
    {
        return DB::transaction(function () use ($batch, $input, $company) {
            $locked = PaymentBatch::where('company_id', $company->id)->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new RegistryConflictException('Only a draft Payment Batch can change its items.');
            }
            $ids = collect($input['payment_ids'])->map(fn ($id) => (string) $id)->unique()->values();
            if ($input['action'] === 'add') {
                $existing = $locked->items()->pluck('payment_instruction_id')->map(fn ($id) => (string) $id);
                $payments = PaymentInstruction::where('company_id', $company->id)->whereIn('id', $ids->diff($existing)->all())->lockForUpdate()->with(['paymentMethod', 'cashAccount', 'currency'])->get();
                if ($payments->count() !== $ids->diff($existing)->count()) {
                    throw new RegistryConflictException('Every added Payment must belong to the current company.');
                }
                $this->assertBatchCompatibility($locked->items()->with('payment.paymentMethod', 'payment.cashAccount', 'payment.currency')->get()->pluck('payment')->filter()->concat($payments)->values());
                foreach ($payments as $payment) {
                    if (! in_array($payment->status, ['ready', 'scheduled'], true) || ($payment->scheduled_date && $payment->scheduled_date->isFuture())) {
                        throw new RegistryConflictException('Only due approved Payments can be added to a batch.');
                    }
                    PaymentBatchItem::create(['id' => (string) Str::uuid(), 'payment_batch_id' => $locked->id, 'company_id' => $company->id, 'payment_instruction_id' => $payment->id, 'status' => 'queued']);
                }
            } else {
                $locked->items()->whereIn('payment_instruction_id', $ids->all())->where('status', 'queued')->delete();
            }
            $this->refreshBatchTotals($locked);

            return $locked->refresh()->load(['items.payment', 'statusHistory']);
        });
    }

    public function attention(Company $company): array
    {
        return ['pending_approval' => PaymentInstruction::where('company_id', $company->id)->where('status', 'pending_approval')->count(), 'pending_confirmation' => PaymentInstruction::where('company_id', $company->id)->where('status', 'pending_confirmation')->count(), 'failed' => PaymentInstruction::where('company_id', $company->id)->whereIn('status', ['failed', 'rejected'])->count(), 'unallocated_amount' => (string) PaymentInstruction::where('company_id', $company->id)->whereIn('status', ['confirmed', 'partially_allocated'])->sum('unapplied_amount'), 'stopped_checks' => PaymentInstrument::where('company_id', $company->id)->where('instrument_type', 'check')->whereIn('status', ['stopped', 'stale'])->count(), 'partial_batches' => PaymentBatch::where('company_id', $company->id)->where('status', 'partially_completed')->count()];
    }

    public function report(Company $company, Request $request): array
    {
        $type = $request->string('type', 'payment-register')->toString();
        $query = match ($type) {
            'check-register' => PaymentInstrument::where('company_id', $company->id)->where('instrument_type', 'check')->with('payment.supplier'),
            'allocation-register' => PaymentAllocation::where('company_id', $company->id)->with(['payment.supplier', 'payable']),
            'unallocated' => PaymentInstruction::where('company_id', $company->id)->where('unapplied_amount', '>', 0)->with(['supplier', 'currency']),
            'pending' => PaymentInstruction::where('company_id', $company->id)->whereIn('status', ['pending_confirmation', 'failed', 'rejected'])->with(['supplier', 'currency']),
            default => PaymentInstruction::where('company_id', $company->id)->with(['supplier', 'currency', 'cashAccount']),
        };
        if ($request->filled('as_of')) {
            $query->where('created_at', '<=', $request->input('as_of'));
        }
        $paginator = $query->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 25), 100));

        return [$paginator->items(), ['report' => $type, 'as_of' => $request->input('as_of', now()->toIso8601String()), 'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    public function voucher(PaymentInstruction $payment, Company $company, Request $request, bool $reprint = false): PaymentVoucher
    {
        return DB::transaction(function () use ($payment, $company, $request, $reprint) {
            $locked = $this->lockedPayment($payment, $company);
            if (! in_array($locked->status, ['released', 'pending_confirmation', 'confirmed', 'partially_allocated', 'allocated', 'reversed', 'voided'], true)) {
                throw new RegistryConflictException('A Disbursement Voucher requires an approved, released, or completed Payment.');
            }
            $voucher = PaymentVoucher::where('company_id', $company->id)->where('payment_instruction_id', $locked->id)->lockForUpdate()->first();
            if (! $voucher) {
                $voucher = PaymentVoucher::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payment_instruction_id' => $locked->id, 'voucher_number' => $this->numbers->next($company->id, 'payment_voucher'), 'status' => 'issued', 'created_by' => $request->user()?->id, 'issued_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id')]);
            } elseif ($reprint) {
                $voucher->update(['reprint_count' => $voucher->reprint_count + 1, 'last_reprinted_at' => now()]);
            }
            $this->audit->record($request, $reprint ? 'payment.voucher.reprinted' : 'payment.voucher.issued', $voucher, $company->id, [], ['voucher_number' => $voucher->voucher_number, 'reprint_count' => $voucher->reprint_count], null, $reprint ? 'Disbursement Voucher reprinted' : 'Disbursement Voucher issued', 'The voucher is evidence only and creates no financial effect.');

            return $voucher->refresh();
        });
    }

    private function unapplyLocked(PaymentInstruction $payment, PaymentAllocation $allocation, Company $company, Request $request, string $correctionType, string $reason): void
    {
        if ($allocation->status !== 'applied') {
            throw new RegistryConflictException('Only an applied Payment Allocation can be corrected.');
        }
        $payable = $allocation->payable_open_item_id ? PayableOpenItem::where('company_id', $company->id)->whereKey($allocation->payable_open_item_id)->lockForUpdate()->firstOrFail() : null;
        $obligation = $allocation->expense_obligation_id ? ExpenseObligation::where('company_id', $company->id)->whereKey($allocation->expense_obligation_id)->lockForUpdate()->firstOrFail() : null;
        $reimbursement = $allocation->reimbursement_obligation_id ? ReimbursementObligation::where('company_id', $company->id)->whereKey($allocation->reimbursement_obligation_id)->lockForUpdate()->firstOrFail() : null;
        if (! $payable && ! $obligation && ! $reimbursement) {
            throw new RegistryConflictException('The Payment Allocation has no valid source owner.');
        }
        $amount = (string) $allocation->amount;
        $counter = PaymentAllocation::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $payment->id, 'payment_confirmation_id' => $allocation->payment_confirmation_id, 'payable_open_item_id' => $payable?->id, 'expense_obligation_id' => $obligation?->id, 'reimbursement_obligation_id' => $reimbursement?->id, 'company_id' => $company->id, 'currency_id' => $allocation->currency_id, 'amount' => $amount, 'allocation_date' => now()->toDateString(), 'status' => 'unapplied', 'correction_type' => $correctionType, 'correction_reason' => $reason, 'parent_allocation_id' => $allocation->id, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        if ($payable) {
            PayableEffect::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payable_open_item_id' => $payable->id, 'source_type' => PaymentAllocation::class, 'source_id' => $counter->id, 'effect_type' => 'payment_allocation_reversal', 'amount_delta' => $amount, 'currency_id' => $allocation->currency_id, 'description' => 'Payment allocation correction '.$payment->payment_number, 'created_by' => $request->user()?->id, 'accounting_transaction_id' => $payment->accounting_transaction_id]);
            $payable->remaining_amount = bcadd((string) $payable->remaining_amount, $amount, 6);
            $paid = bcsub((string) $payable->paid_amount, $amount, 6);
            $payable->paid_amount = bccomp($paid, '0', 6) < 0 ? '0' : $paid;
            $payable->settlement_status = bccomp((string) $payable->remaining_amount, '0', 6) === 0 ? 'settled' : ($payable->paid_amount > 0 ? 'partially_paid' : 'unpaid');
            $payable->version++;
            $payable->last_calculated_at = now();
            $payable->save();
        } elseif ($obligation) {
            $this->expenseSettlement->reverse($obligation, $amount, $company, $request);
        } else {
            $this->reimbursementSettlement->reverse($reimbursement, $amount, $company, $request);
        }
        $allocation->update(['status' => 'unapplied', 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'reversal_allocation_id' => $counter->id]);
        $source = $payable ? $payment->sources()->where('payable_open_item_id', $payable->id)->lockForUpdate()->first() : ($obligation ? $payment->sources()->where('expense_obligation_id', $obligation->id)->lockForUpdate()->first() : $payment->sources()->where('reimbursement_obligation_id', $reimbursement->id)->lockForUpdate()->first());
        if ($source) {
            $sourceAllocated = bcsub((string) $source->allocated_amount, $amount, 6);
            $source->update(['allocated_amount' => bccomp($sourceAllocated, '0', 6) < 0 ? '0' : $sourceAllocated]);
        }
        $allocated = bcsub((string) $payment->allocated_amount, $amount, 6);
        $payment->allocated_amount = bccomp($allocated, '0', 6) < 0 ? '0' : $allocated;
        $payment->unapplied_amount = bcadd((string) $payment->unapplied_amount, $amount, 6);
        $payment->allocation_state = 'partially_allocated';
        $payment->status = $payment->allocated_amount > 0 ? 'partially_allocated' : 'confirmed';
        $payment->version++;
        $payment->save();
        $this->event('EVT-PAY-016', PaymentAllocation::class, $counter->id, $company, $request);
    }

    private function applyToPayableLocked(PaymentInstruction $payment, string $payableId, string $amount, Company $company, Request $request, string $correctionType, string $reason, ?PaymentAdvance $advance = null): void
    {
        if (! in_array($payment->status, ['confirmed', 'partially_allocated', 'allocated'], true)) {
            throw new RegistryConflictException('Only a confirmed Payment can be allocated.');
        }
        if (bccomp($amount, (string) $payment->unapplied_amount, 6) > 0) {
            throw new RegistryConflictException('The Payment available amount is insufficient.');
        }
        $payable = PayableOpenItem::where('company_id', $company->id)->whereKey($payableId)->lockForUpdate()->firstOrFail();
        if ((string) $payable->supplier_id !== (string) $payment->supplier_id || (string) $payable->currency_id !== (string) $payment->currency_id || $payable->hold_status !== 'not_held' || bccomp((string) $payable->remaining_amount, $amount, 6) < 0) {
            throw new RegistryConflictException('The target Payable is outside the Payment supplier/currency scope or cannot accept the amount.');
        }
        if ($advance && bccomp($amount, (string) $advance->available_amount, 6) > 0) {
            throw new RegistryConflictException('The Supplier Advance available amount is insufficient.');
        }
        $confirmation = $payment->confirmations()->where('status', 'confirmed')->latest()->firstOrFail();
        $source = $payment->sources()->where('payable_open_item_id', $payable->id)->lockForUpdate()->first();
        if (! $source) {
            $source = $payment->sources()->create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payable_open_item_id' => $payable->id, 'source_document_number' => $payable->source_document_number, 'currency_id' => $payment->currency_id, 'requested_amount' => $amount, 'allocated_amount' => '0']);
        }
        $reclassId = $advance ? $this->reclassAdvance($payment, $amount, $company, $request, 'to_payable') : $payment->accounting_transaction_id;
        $allocation = PaymentAllocation::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $payment->id, 'payment_confirmation_id' => $confirmation->id, 'payable_open_item_id' => $payable->id, 'company_id' => $company->id, 'currency_id' => $payment->currency_id, 'amount' => $amount, 'allocation_date' => now()->toDateString(), 'status' => 'applied', 'correction_type' => $correctionType, 'payment_advance_id' => $advance?->id, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        PayableEffect::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payable_open_item_id' => $payable->id, 'source_type' => PaymentAllocation::class, 'source_id' => $allocation->id, 'effect_type' => 'payment_allocation', 'amount_delta' => '-'.$amount, 'currency_id' => $payment->currency_id, 'description' => 'Payment allocation '.$payment->payment_number, 'created_by' => $request->user()?->id, 'accounting_transaction_id' => $reclassId]);
        $payable->remaining_amount = bcsub((string) $payable->remaining_amount, $amount, 6);
        $payable->paid_amount = bcadd((string) $payable->paid_amount, $amount, 6);
        $payable->settlement_status = bccomp((string) $payable->remaining_amount, '0', 6) === 0 ? 'settled' : 'partially_paid';
        $payable->version++;
        $payable->last_calculated_at = now();
        $payable->save();
        $source->update(['requested_amount' => bcadd((string) $source->requested_amount, $amount, 6), 'allocated_amount' => bcadd((string) $source->allocated_amount, $amount, 6)]);
        if ($advance) {
            $advance->applied_amount = bcadd((string) $advance->applied_amount, $amount, 6);
            $advance->available_amount = bcsub((string) $advance->available_amount, $amount, 6);
            $advance->status = bccomp((string) $advance->available_amount, '0', 6) === 0 ? 'applied' : 'available';
            $advance->save();
        }
        $payment->allocated_amount = bcadd((string) $payment->allocated_amount, $amount, 6);
        $payment->unapplied_amount = bcsub((string) $payment->unapplied_amount, $amount, 6);
        $payment->allocation_state = bccomp((string) $payment->unapplied_amount, '0', 6) === 0 ? 'allocated' : 'partially_allocated';
        $payment->status = $payment->allocation_state === 'allocated' ? 'allocated' : 'partially_allocated';
        $payment->version++;
        $payment->save();
        $this->event('EVT-PAY-016', PaymentAllocation::class, $allocation->id, $company, $request);
    }

    private function reclassAdvance(PaymentInstruction $payment, string $amount, Company $company, Request $request, string $direction): string
    {
        $advance = $this->advanceAccount($company);
        $payable = $this->payableAccount($company);
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'supplier_advance_reclassification', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => now()->toDateString(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key')]);
        $transaction = AccountingTransaction::create(['id' => (string) Str::uuid(), 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => 'supplier_advance_reclassification', 'status' => 'posted', 'business_date' => now()->toDateString(), 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
        $debit = $direction === 'to_payable' ? $payable->id : $advance->id;
        $credit = $direction === 'to_payable' ? $advance->id : $payable->id;
        AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $transaction->id, 'account_title_id' => $debit, 'debit' => $amount, 'credit' => '0', 'currency_code' => $payment->currency->code, 'description' => 'Supplier Advance reclassification']);
        AccountingTransactionLine::create(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $transaction->id, 'account_title_id' => $credit, 'debit' => '0', 'credit' => $amount, 'currency_code' => $payment->currency->code, 'description' => 'Supplier Advance reclassification']);

        return $transaction->id;
    }

    private function cashOffsetForMovement(CashMovement $movement, Company $company): string
    {
        $account = CashAccount::where('company_id', $company->id)->whereKey($movement->cash_account_id)->firstOrFail();
        $line = AccountingTransactionLine::where('accounting_transaction_id', $movement->accounting_transaction_id)->where('account_title_id', '!=', $account->account_title_id)->first();
        if (! $line) {
            throw new RegistryConflictException('The payment accounting offset could not be identified safely.');
        }

        return $line->account_title_id;
    }

    private function assertBatchCompatibility($payments): void
    {
        $first = $payments->first();
        if (! $first || $payments->contains(fn ($payment) => (string) $payment->payment_method_id !== (string) $first->payment_method_id || (string) $payment->cash_account_id !== (string) $first->cash_account_id || (string) $payment->currency_id !== (string) $first->currency_id)) {
            throw new RegistryConflictException('Batch Payments must use one compatible method, Cash Account, and currency.');
        }
    }

    private function refreshBatchTotals(PaymentBatch $batch): void
    {
        $total = '0';
        $count = 0;
        foreach ($batch->items()->with('payment')->get() as $item) {
            $total = bcadd($total, (string) $item->payment->net_amount, 6);
            $count++;
        }
        $batch->update(['control_total' => $total, 'item_count' => $count, 'succeeded_count' => $batch->items()->where('status', 'succeeded')->count(), 'failed_count' => $batch->items()->where('status', 'failed')->count(), 'version' => $batch->version + 1]);
    }

    private function revalidateBatch(PaymentBatch $batch, Company $company, bool $release = false): void
    {
        $payments = PaymentInstruction::where('company_id', $company->id)->whereIn('id', $batch->items()->pluck('payment_instruction_id'))->lockForUpdate()->with(['paymentMethod', 'cashAccount', 'currency', 'sources.payable'])->get();
        if ($payments->count() !== $batch->items()->count()) {
            throw new RegistryConflictException('A Payment Batch item is outside the current company scope.');
        }
        $this->assertBatchCompatibility($payments);
        foreach ($payments as $payment) {
            if (! in_array($payment->status, ['ready', 'scheduled'], true) && ! ($release && $payment->status === 'released')) {
                throw new RegistryConflictException('A Payment Batch item is no longer payment-ready.', ['payment_id' => $payment->id]);
            }
            if ($payment->scheduled_date && $payment->scheduled_date->isFuture()) {
                throw new RegistryConflictException('A scheduled Payment Batch item is not yet due.', ['payment_id' => $payment->id]);
            }
        }
    }

    private function batchHistory(PaymentBatch $batch, ?string $from, string $to, Request $request, ?string $reason = null): void
    {
        PaymentBatchStatusHistory::create(['id' => (string) Str::uuid(), 'payment_batch_id' => $batch->id, 'company_id' => $batch->company_id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function lockedPayment(PaymentInstruction $payment, Company $company): PaymentInstruction
    {
        return PaymentInstruction::where('company_id', $company->id)->whereKey($payment->id)->lockForUpdate()->with($this->relations())->firstOrFail();
    }

    private function paymentHistory(PaymentInstruction $payment, ?string $from, string $to, string $event, Request $request, ?string $reason): void
    {
        PaymentStatusHistory::create(['id' => (string) Str::uuid(), 'payment_instruction_id' => $payment->id, 'company_id' => $payment->company_id, 'from_status' => $from, 'to_status' => $to, 'event_code' => $event, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $payment->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function event(string $event, string $type, string $id, Company $company, Request $request): void
    {
        Event::dispatch(new PaymentLifecycleEvent($event, $company->id, $type, $id, $request->user()?->id, $request->attributes->get('correlation_id')));
    }

    private function relations(): array
    {
        return ['supplier', 'branch', 'currency', 'paymentMethod', 'cashAccount.currency', 'sources.payable', 'sources.expenseObligation.expense', 'sources.reimbursementObligation.claim', 'allocations', 'allocations.reimbursementObligation.claim', 'approvals', 'instruments', 'attempts', 'confirmations', 'statusHistory', 'remittanceAdvice', 'advance', 'corrections', 'batchItems.batch', 'voucher'];
    }

    private function advanceAccount(Company $company): AccountTitle
    {
        $account = AccountTitle::where('company_id', $company->id)->where('status', 'active')->where('posting_eligible', true)->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%supplier advance%'])->orWhereRaw('LOWER(name) LIKE ?', ['%advance to supplier%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%advance%']))->first();
        if (! $account) {
            throw new RegistryConflictException('An active posting Supplier Advances Account Title is required.');
        }

        return $account;
    }

    private function payableAccount(Company $company): AccountTitle
    {
        $account = AccountTitle::where('company_id', $company->id)->where('classification', 'liability')->where('status', 'active')->where('posting_eligible', true)->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%payable%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%payable%'])->orWhereRaw('LOWER(code) LIKE ?', ['%payable%']))->first();
        if (! $account) {
            throw new RegistryConflictException('An active posting Accounts Payable Account Title is required.');
        }

        return $account;
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value) || bccomp((string) $value, '0', 6) <= 0) {
            throw new RegistryConflictException('The amount must be greater than zero.');
        }

        return bcadd((string) $value, '0', 6);
    }

    private function requireReason(?string $reason, string $message): void
    {
        if (! trim((string) $reason)) {
            throw new RegistryConflictException($message);
        }
    }
}
