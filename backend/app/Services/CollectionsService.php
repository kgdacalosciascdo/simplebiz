<?php

namespace App\Services;

use App\Events\CollectionsLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\CashRemittance;
use App\Models\CashRemittanceLine;
use App\Models\CashTransferDocument;
use App\Models\CollectionActivity;
use App\Models\Company;
use App\Models\CustomerUnappliedReceipt;
use App\Models\OtherReceiptType;
use App\Models\PaymentApplication;
use App\Models\PaymentApplicationHistory;
use App\Models\PaymentMethod;
use App\Models\ReasonCode;
use App\Models\Receipt;
use App\Models\ReceiptReprint;
use App\Models\ReceiptStatusHistory;
use App\Models\ReceiptTender;
use App\Models\ReceivableOpenItem;
use App\Models\ReferenceCurrency;
use App\Models\RemittanceVariance;
use App\Models\Sale;
use App\Support\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CollectionsService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers, private readonly CashMovementService $cash, private readonly CashTransferService $transfers) {}

    public function summary(Company $company): array
    {
        $receipts = Receipt::where('company_id', $company->id);
        $unapplied = CustomerUnappliedReceipt::where('company_id', $company->id)->where('available_amount', '>', 0);

        return ['receipts' => ['total' => (clone $receipts)->count(), 'draft' => (clone $receipts)->where('status', 'draft')->count(), 'for_approval' => (clone $receipts)->where('status', 'for_approval')->count(), 'posted' => (clone $receipts)->where('status', 'posted')->count()], 'unapplied' => ['count' => (clone $unapplied)->count(), 'amount' => (string) (clone $unapplied)->sum('available_amount')], 'open_receivables' => ReceivableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->count()];
    }

    public function lookups(Company $company): array
    {
        return ['customers' => BusinessPartner::where('company_id', $company->id)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'customer')->where('status', 'active'))->orderBy('display_name')->get(['id', 'code', 'display_name']), 'currencies' => ReferenceCurrency::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'symbol', 'decimal_precision']), 'payment_methods' => PaymentMethod::where('company_id', $company->id)->where('status', 'active')->where('supports_incoming', true)->orderBy('name')->get(['id', 'code', 'name', 'requires_external_reference', 'requires_account_selection', 'clearing_behavior']), 'cash_accounts' => CashAccount::where('company_id', $company->id)->where('status', 'active')->whereHas('capabilities', fn ($q) => $q->where('capability', 'RECEIVE_FUNDS')->where('enabled', true))->with('currency')->orderBy('name')->get(['id', 'code', 'name', 'display_name', 'currency_id']), 'open_items' => ReceivableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->with(['customer', 'currency'])->orderBy('due_date')->limit(200)->get()];
    }

    public function listReceipts(Company $company, Request $request): array
    {
        $query = Receipt::where('company_id', $company->id)->with(['customer', 'currency'])->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')))->when($request->filled('receipt_type'), fn ($q) => $q->where('receipt_type', $request->string('receipt_type')))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('receipt_number', 'ilike', '%'.$request->string('q').'%')->orWhere('external_reference', 'ilike', '%'.$request->string('q').'%')->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'ilike', '%'.$request->string('q').'%'))))->latest('receipt_date')->latest('created_at');
        $perPage = min((int) $request->input('per_page', 25), 100);
        $items = $query->paginate($perPage);

        return [$items->getCollection(), ['pagination' => ['total' => $items->total(), 'per_page' => $items->perPage(), 'current_page' => $items->currentPage(), 'last_page' => $items->lastPage()]]];
    }

    public function createDraft(array $input, Company $company, Request $request): Receipt
    {
        $this->validateHeader($input, $company);
        $receipt = DB::transaction(function () use ($input, $company, $request) {
            $receipt = Receipt::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_number' => $this->numbers->next($company->id, 'receipt'), 'receipt_type' => $input['receipt_type'], 'receipt_date' => $input['receipt_date'], 'customer_id' => $input['customer_id'], 'source_sale_id' => $input['source_sale_id'] ?? null, 'currency_id' => $input['currency_id'], 'payer_name_snapshot' => $input['payer_name_snapshot'] ?? null, 'external_reference' => $input['external_reference'] ?? null, 'customer_reference' => $input['customer_reference'] ?? null, 'amount' => $input['amount'], 'notes' => $input['notes'] ?? null, 'status' => 'draft', 'application_status' => 'unapplied', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $this->replaceLines($receipt, $input, $company, $request);
            $this->history($receipt, null, 'draft', $company, $request);

            return $receipt->refresh();
        });
        $this->audit->record($request, 'collections.receipt.drafted', $receipt, $company->id, [], $this->safe($receipt), null, 'Receipt drafted', 'A customer collection receipt draft was created.');
        $this->event('receipt.draft.created', $receipt, $company, $request, $receipt->receipt_date?->toDateString());

        return $this->load($receipt);
    }

    public function otherReceiptTypes(Company $company)
    {
        $this->ensureOtherReceiptTypes($company);

        return OtherReceiptType::where('company_id', $company->id)->where('active', true)->orderBy('name')->get();
    }

    public function createOtherDraft(array $input, Company $company, Request $request): Receipt
    {
        $this->ensureOtherReceiptTypes($company);
        $type = OtherReceiptType::where('company_id', $company->id)->where('code', $input['other_receipt_type'])->where('active', true)->first();
        if (! $type) {
            throw new RegistryConflictException('The selected Other Receipt type is not active for this company.');
        }
        if ($type->requires_source_reference && ! trim((string) ($input['source_reference'] ?? ''))) {
            throw new RegistryConflictException('The selected Other Receipt type requires a source reference.');
        }
        if ($type->source_module && ($input['source_module'] ?? $type->source_module) !== $type->source_module) {
            throw new RegistryConflictException('The selected Other Receipt type requires its configured source module.');
        }
        $receipt = DB::transaction(function () use ($input, $company, $request, $type) {
            $receipt = Receipt::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_number' => $this->numbers->next($company->id, 'receipt'), 'receipt_type' => 'other_receipt', 'other_receipt_type' => $type->code, 'receipt_date' => $input['receipt_date'], 'customer_id' => null, 'currency_id' => $input['currency_id'], 'counterparty_name' => $input['counterparty_name'], 'source_module' => $input['source_module'] ?? $type->source_module, 'source_reference' => $input['source_reference'] ?? null, 'classification' => $type->classification, 'business_purpose' => $input['business_purpose'], 'evidence_reference' => $input['evidence_reference'], 'external_reference' => $input['external_reference'] ?? null, 'amount' => $input['amount'], 'notes' => $input['notes'] ?? null, 'status' => 'draft', 'application_status' => 'not_applicable', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $this->replaceLines($receipt, ['tenders' => $input['tenders'], 'applications' => []], $company, $request);
            $this->history($receipt, null, 'draft', $company, $request);

            return $receipt->refresh();
        });
        $this->audit->record($request, 'collections.other-receipt.drafted', $receipt, $company->id, [], $this->safe($receipt), null, 'Other Receipt drafted', 'A controlled Other Receipt draft was created with source classification and evidence reference.');
        $this->event('other-receipt.draft.created', $receipt, $company, $request, $receipt->receipt_date?->toDateString());

        return $this->load($receipt);
    }

    public function updateDraft(Receipt $receipt, array $input, Company $company, Request $request): Receipt
    {
        $this->scope($receipt, $company);
        if (! in_array($receipt->status, ['draft', 'failed'], true)) {
            throw new RegistryConflictException('Only draft or failed receipts may be edited.');
        }
        $this->version($receipt, $input);
        $this->validateHeader($input + ['source_sale_id' => $input['source_sale_id'] ?? $receipt->source_sale_id], $company);
        $updated = DB::transaction(function () use ($receipt, $input, $company, $request) {
            $locked = Receipt::whereKey($receipt->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $locked->update(array_merge(array_intersect_key($input, array_flip(['receipt_type', 'receipt_date', 'customer_id', 'source_sale_id', 'currency_id', 'amount', 'payer_name_snapshot', 'external_reference', 'customer_reference'])), ['status' => 'draft', 'version' => $locked->version + 1]));
            $locked->tenders()->delete();
            $locked->applications()->delete();
            $this->replaceLines($locked, $input, $company, $request);

            return $locked->refresh();
        });

        return $this->load($updated);
    }

    public function transition(Receipt $receipt, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): Receipt
    {
        $this->scope($receipt, $company);
        if ($version !== null && (int) $receipt->version !== $version) {
            throw new RegistryConflictException('This receipt was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        if ($action === 'post') {
            return $this->post($receipt, $company, $request);
        }
        if ($action === 'reverse') {
            return $this->reverse($receipt, $reason, $company, $request);
        }
        $result = DB::transaction(function () use ($receipt, $action, $reason, $company, $request) {
            $locked = Receipt::whereKey($receipt->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $updates = ['version' => $locked->version + 1];
            $actor = $request->user()?->id;
            if ($action === 'submit') {
                $this->validateReady($locked, $company);
                if ($locked->status !== 'draft') {
                    throw new RegistryConflictException('Only draft receipts may be submitted.');
                } $updates += ['status' => 'for_approval', 'submitted_by' => $actor, 'submitted_at' => now()];
            } elseif ($action === 'review') {
                if ($locked->status !== 'for_approval') {
                    throw new RegistryConflictException('Only receipts awaiting approval may be reviewed.');
                } $updates += ['reviewed_by' => $actor, 'reviewed_at' => now()];
            } elseif ($action === 'approve') {
                if ($locked->status !== 'for_approval') {
                    throw new RegistryConflictException('Only receipts awaiting approval may be approved.');
                } if ((int) $locked->created_by === (int) $actor) {
                    throw new RegistryConflictException('The preparer cannot approve the same receipt.');
                } $updates += ['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now()];
            } elseif ($action === 'cancel') {
                if (! in_array($locked->status, ['draft', 'for_approval', 'approved', 'failed'], true)) {
                    throw new RegistryConflictException('This receipt cannot be voided from its current status.');
                } $this->requireReason($reason);
                $updates += ['status' => 'voided', 'voided_by' => $actor, 'voided_at' => now(), 'correction_reason' => $reason];
            } else {
                throw new RegistryConflictException('Unsupported receipt action.');
            }
            $locked->update($updates);
            $this->history($locked, $from, $locked->status, $company, $request, $reason);

            return $locked->refresh();
        });
        $this->audit->record($request, 'collections.receipt.'.($action === 'cancel' ? 'voided' : $action), $result, $company->id, [], $this->safe($result), $reason, 'Receipt lifecycle', 'Receipt lifecycle status changed.');
        if ($action === 'submit') {
            $this->event('receipt.submitted', $result, $company, $request, $result->receipt_date?->toDateString());
        } elseif ($action === 'cancel') {
            $this->event('receipt.voided', $result, $company, $request, $result->receipt_date?->toDateString());
        }

        return $this->load($result);
    }

    public function post(Receipt $receipt, Company $company, Request $request): Receipt
    {
        $result = DB::transaction(function () use ($receipt, $company, $request) {
            $locked = Receipt::whereKey($receipt->id)->where('company_id', $company->id)->lockForUpdate()->with(['tenders', 'applications'])->firstOrFail();
            if ($locked->status !== 'approved') {
                throw new RegistryConflictException('Only approved receipts may be posted.');
            }
            $this->validateReady($locked, $company);
            $tenders = $locked->tenders()->orderBy('id')->lockForUpdate()->get();
            $applications = $locked->applications()->orderBy('receivable_open_item_id')->lockForUpdate()->get();
            $this->validateTotals($locked, $tenders, $applications);
            $currency = ReferenceCurrency::whereKey($locked->currency_id)->where('company_id', $company->id)->firstOrFail();
            $isOtherReceipt = $locked->receipt_type === 'other_receipt';
            $receivableTitle = $isOtherReceipt ? null : $this->postingAccount($company, 'asset', ['receivable']);
            $advanceTitle = ! $isOtherReceipt && $locked->unapplied_amount > 0 ? $this->postingAccount($company, 'liability', ['advance', 'deposit', 'unapplied', 'customer']) : null;
            $otherTitle = $isOtherReceipt ? $this->otherReceiptPostingAccount($company, (string) $locked->classification) : null;
            $businessId = (string) Str::uuid();
            $transactionType = $isOtherReceipt ? 'other_receipt' : 'customer_receipt';
            DB::table('business_transactions')->insert(['id' => $businessId, 'company_id' => $company->id, 'transaction_type' => $transactionType, 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $locked->receipt_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key'), 'created_at' => now(), 'updated_at' => now()]);
            $accountingId = (string) Str::uuid();
            DB::table('accounting_transactions')->insert(['id' => $accountingId, 'business_transaction_id' => $businessId, 'company_id' => $company->id, 'transaction_type' => $transactionType, 'status' => 'posted', 'business_date' => $locked->receipt_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
            $remainingApplied = (string) $locked->applied_total;
            foreach ($tenders as $tender) {
                $appliedPart = min((float) $remainingApplied, (float) $tender->amount);
                $unappliedPart = (float) $tender->amount - $appliedPart;
                $offsets = [];
                if ($isOtherReceipt) {
                    $offsets[] = ['account_title_id' => $otherTitle->id, 'amount' => (string) $tender->amount, 'description' => 'Controlled Other Receipt inflow'];
                } else {
                    if ($appliedPart > 0) {
                        $offsets[] = ['account_title_id' => $receivableTitle->id, 'amount' => number_format($appliedPart, 6, '.', ''), 'description' => 'Customer Receipt applied to receivable'];
                    }
                    if ($unappliedPart > 0) {
                        $offsets[] = ['account_title_id' => $advanceTitle->id, 'amount' => number_format($unappliedPart, 6, '.', ''), 'description' => 'Customer Receipt customer advance'];
                    }
                }
                $account = CashAccount::whereKey($tender->cash_account_id)->where('company_id', $company->id)->with('currency')->lockForUpdate()->firstOrFail();
                $method = PaymentMethod::whereKey($tender->payment_method_id)->where('company_id', $company->id)->where('status', 'active')->firstOrFail();
                $this->cash->createIncomingReceiptEffect($account, $company, (string) $tender->amount, $currency->code, $locked->receipt_date->toDateString(), $accountingId, $offsets, $locked, $method, $request);
                $remainingApplied = bcsub($remainingApplied, (string) $appliedPart, 6);
            }
            foreach ($applications as $application) {
                $item = ReceivableOpenItem::whereKey($application->receivable_open_item_id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
                $this->validateApplication($locked, $item, (string) $application->amount);
                $item->applied_amount = bcadd((string) $item->applied_amount, (string) $application->amount, 6);
                $item->remaining_amount = bcsub((string) $item->remaining_amount, (string) $application->amount, 6);
                $item->settlement_status = (float) $item->remaining_amount <= 0 ? 'paid' : 'partially_paid';
                $item->last_calculated_at = now();
                $item->version++;
                $item->save();
                $application->update(['status' => (float) $application->amount >= (float) $item->remaining_amount ? 'fully_applied' : 'partially_applied', 'applied_by' => $request->user()?->id, 'applied_at' => now(), 'version' => $application->version + 1]);
                $this->applicationHistory($application, 'unapplied', $application->status, $company, $request);
                if ($item->source_sale_id) {
                    $sourceSale = Sale::whereKey($item->source_sale_id)->where('company_id', $company->id)->lockForUpdate()->first();
                    if ($sourceSale) {
                        $sourceSale->paid_amount = bcadd((string) $sourceSale->paid_amount, (string) $application->amount, 6);
                        $sourceSale->remaining_amount = bcsub((string) $sourceSale->remaining_amount, (string) $application->amount, 6);
                        if (bccomp((string) $sourceSale->remaining_amount, '0', 6) < 0) {
                            $sourceSale->remaining_amount = '0';
                        }
                        $sourceSale->settlement_status = $item->settlement_status;
                        $sourceSale->version++;
                        $sourceSale->save();
                    }
                }
            }
            if (! $isOtherReceipt && (float) $locked->unapplied_amount > 0) {
                CustomerUnappliedReceipt::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_id' => $locked->id, 'customer_id' => $locked->customer_id, 'currency_id' => $locked->currency_id, 'original_amount' => $locked->unapplied_amount, 'available_amount' => $locked->unapplied_amount, 'received_date' => $locked->receipt_date, 'status' => 'available']);
            }
            $locked->update(['status' => 'posted', 'posted_by' => $request->user()?->id, 'posted_at' => now(), 'business_transaction_id' => $businessId, 'accounting_transaction_id' => $accountingId, 'version' => $locked->version + 1]);
            $this->history($locked, 'approved', 'posted', $company, $request);

            return $locked->refresh();
        });
        $this->audit->record($request, $result->receipt_type === 'other_receipt' ? 'collections.other-receipt.posted' : 'collections.receipt.posted', $result, $company->id, [], $this->safe($result), null, 'Receipt posted', 'Receipt, governed applications where applicable, MDS-700 cash effects, and balanced accounting were posted atomically.');
        $this->event($result->receipt_type === 'other_receipt' ? 'other-receipt.posted' : 'receipt.posted', $result, $company, $request, $result->receipt_date?->toDateString());
        if ($result->receipt_type !== 'other_receipt' && (float) $result->unapplied_amount > 0) {
            $this->event('advance.unapplied.created', $result, $company, $request, $result->receipt_date?->toDateString());
        }
        if ($result->receipt_type !== 'other_receipt' && (float) $result->applied_total > 0) {
            $this->event('payment.applied', $result, $company, $request, $result->receipt_date?->toDateString());
            $this->event((float) $result->unapplied_amount > 0 ? 'receipt.partially.applied' : 'receipt.fully.applied', $result, $company, $request, $result->receipt_date?->toDateString());
        }

        return $this->load($result);
    }

    public function applyUnapplied(array $input, Company $company, Request $request): PaymentApplication
    {
        $result = DB::transaction(function () use ($input, $company, $request) {
            $unapplied = CustomerUnappliedReceipt::where('company_id', $company->id)->whereKey($input['unapplied_receipt_id'])->lockForUpdate()->firstOrFail();
            $receipt = Receipt::whereKey($unapplied->receipt_id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $item = ReceivableOpenItem::where('company_id', $company->id)->whereKey($input['receivable_open_item_id'])->lockForUpdate()->firstOrFail();
            if ($unapplied->status !== 'available' || (float) $input['amount'] > (float) $unapplied->available_amount) {
                throw new RegistryConflictException('The unapplied receipt does not have enough available customer credit.');
            }
            $this->validateApplication($receipt, $item, (string) $input['amount']);
            $this->applicationAccounting($company, $request, $input['application_date'], (string) $input['amount'], false);
            $app = PaymentApplication::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_id' => $receipt->id, 'customer_id' => $receipt->customer_id, 'receivable_open_item_id' => $item->id, 'currency_id' => $receipt->currency_id, 'amount' => $input['amount'], 'application_date' => $input['application_date'], 'status' => 'fully_applied', 'applied_by' => $request->user()?->id, 'applied_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $item->applied_amount = bcadd((string) $item->applied_amount, (string) $input['amount'], 6);
            $item->remaining_amount = bcsub((string) $item->remaining_amount, (string) $input['amount'], 6);
            $item->settlement_status = (float) $item->remaining_amount <= 0 ? 'paid' : 'partially_paid';
            $item->version++;
            $item->last_calculated_at = now();
            $item->save();
            if ($item->source_sale_id) {
                $sourceSale = Sale::whereKey($item->source_sale_id)->where('company_id', $company->id)->lockForUpdate()->first();
                if ($sourceSale) {
                    $sourceSale->paid_amount = bcadd((string) $sourceSale->paid_amount, (string) $input['amount'], 6);
                    $sourceSale->remaining_amount = bcsub((string) $sourceSale->remaining_amount, (string) $input['amount'], 6);
                    if (bccomp((string) $sourceSale->remaining_amount, '0', 6) < 0) {
                        $sourceSale->remaining_amount = '0';
                    }
                    $sourceSale->settlement_status = $item->settlement_status;
                    $sourceSale->version++;
                    $sourceSale->save();
                }
            }
            $unapplied->applied_later_amount = bcadd((string) $unapplied->applied_later_amount, (string) $input['amount'], 6);
            $unapplied->available_amount = bcsub((string) $unapplied->available_amount, (string) $input['amount'], 6);
            $unapplied->status = (float) $unapplied->available_amount <= 0 ? 'fully_applied' : 'available';
            $unapplied->version++;
            $unapplied->save();
            $receipt->applied_total = bcadd((string) $receipt->applied_total, (string) $input['amount'], 6);
            $receipt->unapplied_amount = bcsub((string) $receipt->unapplied_amount, (string) $input['amount'], 6);
            $receipt->application_status = (float) $receipt->unapplied_amount <= 0 ? 'fully_applied' : 'partially_applied';
            $receipt->version++;
            $receipt->save();
            $this->applicationHistory($app, null, 'fully_applied', $company, $request);

            return $app->refresh();
        });
        $this->audit->record($request, 'collections.application.created', $result, $company->id, [], $result->toArray(), null, 'Payment applied', 'Previously unapplied customer credit was applied to an MDS-200 receivable open item.');
        $this->event('payment.applied', $result, $company, $request, $result->application_date?->toDateString());

        return $result->load(['receipt', 'receivable', 'customer']);
    }

    public function reverseApplication(PaymentApplication $application, string $reason, Company $company, Request $request): PaymentApplication
    {
        $this->requireReason($reason);
        $result = DB::transaction(function () use ($application, $reason, $company, $request) {
            $app = PaymentApplication::whereKey($application->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($app->status === 'reversed') {
                throw new RegistryConflictException('This payment application has already been reversed.');
            }
            $item = ReceivableOpenItem::whereKey($app->receivable_open_item_id)->lockForUpdate()->firstOrFail();
            $receipt = Receipt::whereKey($app->receipt_id)->lockForUpdate()->firstOrFail();
            $this->applicationAccounting($company, $request, now()->toDateString(), (string) $app->amount, true);
            $item->applied_amount = bcsub((string) $item->applied_amount, (string) $app->amount, 6);
            $item->remaining_amount = bcadd((string) $item->remaining_amount, (string) $app->amount, 6);
            $item->settlement_status = (float) $item->applied_amount <= 0 ? 'unpaid' : 'partially_paid';
            $item->version++;
            $item->last_calculated_at = now();
            $item->save();
            $app->update(['status' => 'reversed', 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'version' => $app->version + 1]);
            if ($item->source_sale_id) {
                $sourceSale = Sale::whereKey($item->source_sale_id)->where('company_id', $company->id)->lockForUpdate()->first();
                if ($sourceSale) {
                    $sourceSale->paid_amount = bcsub((string) $sourceSale->paid_amount, (string) $app->amount, 6);
                    if (bccomp((string) $sourceSale->paid_amount, '0', 6) < 0) {
                        $sourceSale->paid_amount = '0';
                    }
                    $sourceSale->remaining_amount = bcadd((string) $sourceSale->remaining_amount, (string) $app->amount, 6);
                    $sourceSale->settlement_status = $item->settlement_status;
                    $sourceSale->version++;
                    $sourceSale->save();
                }
            }
            PaymentApplication::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_id' => $app->receipt_id, 'customer_id' => $app->customer_id, 'receivable_open_item_id' => $app->receivable_open_item_id, 'currency_id' => $app->currency_id, 'amount' => $app->amount, 'application_date' => now()->toDateString(), 'status' => 'reversed', 'original_application_id' => $app->id, 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            if ($receipt->status === 'posted') {
                $receipt->applied_total = bcsub((string) $receipt->applied_total, (string) $app->amount, 6);
                $receipt->unapplied_amount = bcadd((string) $receipt->unapplied_amount, (string) $app->amount, 6);
                $receipt->application_status = 'partially_applied';
                $receipt->version++;
                $receipt->save();
                CustomerUnappliedReceipt::updateOrCreate(['receipt_id' => $receipt->id], ['company_id' => $company->id, 'customer_id' => $receipt->customer_id, 'currency_id' => $receipt->currency_id, 'original_amount' => $receipt->amount, 'applied_later_amount' => DB::raw('GREATEST(applied_later_amount - '.(float) $app->amount.', 0)'), 'available_amount' => DB::raw('available_amount + '.(float) $app->amount), 'status' => 'available', 'received_date' => $receipt->receipt_date, 'updated_at' => now()]);
            }
            $this->applicationHistory($app, 'fully_applied', 'reversed', $company, $request, $reason);

            return $app->refresh();
        });
        $this->audit->record($request, 'collections.application.reversed', $result, $company->id, [], $result->toArray(), $reason, 'Payment application reversed', 'The linked application was reversed and the MDS-200 open item was restored.');
        $this->event('application.reversed', $result, $company, $request, $result->application_date?->toDateString());

        return $result;
    }

    public function unapplied(Company $company, Request $request): array
    {
        $q = CustomerUnappliedReceipt::where('company_id', $company->id)->where('available_amount', '>', 0)->with(['customer', 'currency', 'receipt'])->latest('received_date');
        $page = $q->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->getCollection(), ['pagination' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]];
    }

    public function ledger(Company $company, string $customerId, Request $request): array
    {
        $customer = BusinessPartner::where('company_id', $company->id)->whereKey($customerId)->firstOrFail();
        $items = ReceivableOpenItem::where('company_id', $company->id)->where('customer_id', $customerId)->with('currency')->get()->map(fn ($item) => ['date' => $item->sourceSale?->sale_date?->toDateString() ?? $item->created_at?->toDateString(), 'type' => 'charge', 'reference' => $item->source_document_number, 'debit' => (string) $item->original_amount, 'credit' => '0', 'application_id' => null]);
        $applications = PaymentApplication::where('company_id', $company->id)->where('customer_id', $customerId)->where('status', '!=', 'reversed')->with('receipt')->get()->map(fn ($app) => ['date' => $app->application_date?->toDateString(), 'type' => 'payment_application', 'reference' => $app->receipt?->receipt_number, 'debit' => '0', 'credit' => (string) $app->amount, 'application_id' => $app->id]);
        $rows = $items->concat($applications)->sortBy('date')->values();
        $running = '0';
        $rows = $rows->map(function ($row) use (&$running) {
            $running = bcsub(bcadd($running, $row['debit'], 6), $row['credit'], 6);
            $row['running_balance'] = $running;

            return $row;
        });

        return ['customer' => ['id' => $customer->id, 'code' => $customer->code, 'display_name' => $customer->display_name], 'entries' => $rows, 'as_of' => now()->toISOString()];
    }

    public function load(Receipt $receipt): Receipt
    {
        return $receipt->load(['customer', 'currency', 'tenders.paymentMethod', 'tenders.cashAccount', 'applications.receivable', 'unapplied.customer', 'unapplied.currency', 'statusHistory', 'reprints', 'activities']);
    }

    public function printable(Receipt $receipt, Company $company, Request $request): Receipt
    {
        $this->scope($receipt, $company);
        if (! in_array($receipt->status, ['posted', 'reversed', 'voided', 'failed'], true)) {
            throw new RegistryConflictException('Only an issued receipt can be printed.');
        }

        $printable = $receipt->load([
            'customer',
            'currency',
            'sourceSale.branch',
            'tenders.paymentMethod',
            'tenders.cashAccount',
            'applications.receivable',
            'reprints',
            'postedBy',
            'createdBy',
        ]);
        $reprintId = $request->string('reprint_id')->toString();
        $reprint = $reprintId !== '' ? $printable->reprints->firstWhere('id', $reprintId) : null;
        if ($reprintId !== '' && ! $reprint) {
            throw new RegistryConflictException('The requested reprint record is not associated with this Receipt.');
        }
        $printable->setAttribute('print_company', $company);
        $printable->setAttribute('print_reprint', $reprint);

        return $printable;
    }

    public function reprint(Receipt $receipt, array $input, Company $company, Request $request): ReceiptReprint
    {
        $this->scope($receipt, $company);
        if (! in_array($receipt->status, ['posted', 'reversed', 'voided', 'failed'], true)) {
            throw new RegistryConflictException('Only an issued receipt may be reprinted.');
        }
        $reprint = ReceiptReprint::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_id' => $receipt->id, 'reason' => $input['reason'], 'channel' => $input['channel'] ?? 'screen', 'actor_id' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
        $this->audit->record($request, 'collections.receipt.reprinted', $receipt, $company->id, [], ['receipt_id' => $receipt->id, 'reprint_id' => $reprint->id, 'channel' => $reprint->channel], $reprint->reason, 'Receipt reprinted', 'A receipt copy was prepared without creating a financial effect.');
        $this->event('receipt.reprinted', $receipt, $company, $request, $receipt->receipt_date?->toDateString());

        return $reprint->refresh();
    }

    public function failTender(string $tenderId, array $input, Company $company, Request $request): ReceiptTender
    {
        $tender = DB::transaction(function () use ($tenderId, $input, $company, $request) {
            $tender = ReceiptTender::where('company_id', $company->id)->whereKey($tenderId)->lockForUpdate()->firstOrFail();
            $receipt = Receipt::where('company_id', $company->id)->whereKey($tender->receipt_id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== 'posted') {
                throw new RegistryConflictException('Only a posted receipt can have a failed payment instrument recorded.');
            }
            if (in_array($tender->instrument_status, ['failed', 'returned', 'reversed'], true)) {
                throw new RegistryConflictException('This payment instrument has already been corrected.');
            }
            $tender->update(['instrument_status' => 'failed', 'clearing_status' => 'failed', 'failed_at' => now(), 'failed_by' => $request->user()?->id, 'failure_reason' => $input['reason'], 'failure_reference' => $input['failure_reference'] ?? null, 'version' => $tender->version + 1]);
            $this->audit->record($request, 'collections.payment-instrument.failed', $tender, $company->id, [], ['tender_id' => $tender->id, 'receipt_id' => $receipt->id, 'status' => 'failed'], $input['reason'], 'Payment instrument failed', 'A payment instrument was marked failed and requires governed receipt correction/recovery review.');

            return $tender->refresh();
        });
        $receipt = Receipt::where('company_id', $company->id)->whereKey($tender->receipt_id)->firstOrFail();
        if ($receipt->status === 'posted') {
            $this->reverse($receipt, 'Payment instrument failed: '.$input['reason'], $company, $request);
            $failed = $receipt->fresh();
            $this->history($failed, 'reversed', 'failed', $company, $request, $input['reason']);
            $failed->update(['status' => 'failed', 'correction_reason' => $input['reason'], 'version' => $failed->version + 1]);
        }

        $this->event('payment.instrument.failed', $failed ?? $receipt, $company, $request, $receipt->receipt_date?->toDateString());

        return $tender->fresh();
    }

    public function listActivities(Company $company, Request $request): array
    {
        $query = CollectionActivity::where('company_id', $company->id)->with(['customer', 'receipt', 'receivable'])->latest('occurred_at')->latest('created_at');
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->string('customer_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        $page = $query->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->getCollection(), ['pagination' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]];
    }

    public function createActivity(array $input, Company $company, Request $request): CollectionActivity
    {
        if (! empty($input['customer_id']) && ! BusinessPartner::where('company_id', $company->id)->whereKey($input['customer_id'])->exists()) {
            throw new RegistryConflictException('The activity customer is outside the current company.');
        }
        $activity = CollectionActivity::create(array_merge($input, ['id' => (string) Str::uuid(), 'company_id' => $company->id, 'occurred_at' => $input['occurred_at'] ?? now(), 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]));
        $this->audit->record($request, 'collections.activity.recorded', $activity, $company->id, [], $activity->toArray(), null, 'Collection activity recorded', 'A non-financial collection follow-up activity was recorded.');
        $this->event('collection.activity.recorded', $activity, $company, $request, $activity->occurred_at?->toDateString());

        return $activity->load(['customer', 'receipt', 'receivable']);
    }

    public function createRemittance(array $input, Company $company, Request $request): CashRemittance
    {
        $submittedAmounts = $input['submitted_amounts'] ?? [];
        $remittance = DB::transaction(function () use ($input, $company, $request, $submittedAmounts) {
            $tenders = ReceiptTender::where('company_id', $company->id)->whereIn('id', $input['tender_ids'])->where('remittance_status', 'unremitted')->whereHas('receipt', fn ($q) => $q->where('status', 'posted')->where('currency_id', $input['currency_id']))->with(['receipt', 'cashAccount'])->lockForUpdate()->get();
            if ($tenders->count() !== count(array_unique($input['tender_ids']))) {
                throw new RegistryConflictException('Every selected tender must be a posted, same-currency, unremitted receipt tender.');
            }
            $expected = (string) $tenders->sum('amount');
            $submitted = '0';
            foreach ($tenders as $tender) {
                $submitted = bcadd($submitted, (string) ($submittedAmounts[$tender->id] ?? $tender->amount), 6);
            }
            $sourceIds = $tenders->pluck('cash_account_id')->filter()->unique()->values();
            if ($sourceIds->count() !== 1) {
                throw new RegistryConflictException('A Remittance transfer requires exactly one source Cash Account.');
            }
            $destinationId = $input['destination_cash_account_id'] ?? null;
            if ($destinationId !== null && (string) $sourceIds->first() === (string) $destinationId) {
                throw new RegistryConflictException('The Remittance source and destination Cash Accounts must be different.');
            }
            $remittance = CashRemittance::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'remittance_number' => $this->numbers->next($company->id, 'remittance'), 'cashier_id' => $input['cashier_id'] ?? $request->user()?->id, 'collector_id' => $input['collector_id'] ?? null, 'branch_id' => $input['branch_id'] ?? null, 'currency_id' => $input['currency_id'], 'source_cash_account_id' => $sourceIds->first(), 'destination_cash_account_id' => $destinationId, 'remittance_date' => $input['remittance_date'], 'period_start' => $input['period_start'] ?? null, 'period_end' => $input['period_end'] ?? null, 'status' => 'draft', 'expected_amount' => $expected, 'submitted_amount' => $submitted, 'difference_amount' => bcsub($submitted, $expected, 6), 'evidence_reference' => $input['evidence_reference'] ?? null, 'deposit_reference' => $input['deposit_reference'] ?? null, 'reason' => $input['reason'] ?? null, 'prepared_by' => $request->user()?->id, 'prepared_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            foreach ($tenders as $tender) {
                $actual = (string) ($submittedAmounts[$tender->id] ?? $tender->amount);
                CashRemittanceLine::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_remittance_id' => $remittance->id, 'receipt_tender_id' => $tender->id, 'expected_amount' => $tender->amount, 'submitted_amount' => $actual, 'difference_amount' => bcsub($actual, (string) $tender->amount, 6)]);
            }

            return $remittance;
        });
        $this->audit->record($request, 'collections.remittance.drafted', $remittance, $company->id, [], $remittance->toArray(), null, 'Cash remittance prepared', 'A cash remittance was prepared from accountable posted receipt tenders.');
        $this->event('remittance.created', $remittance, $company, $request, $remittance->remittance_date?->toDateString());

        return $remittance->load(['lines.tender.receipt', 'variances', 'sourceAccount', 'destinationAccount']);
    }

    public function transitionRemittance(CashRemittance $remittance, string $action, array $input, Company $company, Request $request): CashRemittance
    {
        if ((int) $remittance->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The remittance is outside the current company scope.');
        }
        $result = DB::transaction(function () use ($remittance, $action, $input, $company, $request) {
            $locked = CashRemittance::whereKey($remittance->id)->where('company_id', $company->id)->lockForUpdate()->with(['lines.tender.cashAccount', 'variances', 'transfer'])->firstOrFail();
            $updates = ['version' => $locked->version + 1];
            $from = $locked->status;
            $actor = $request->user()?->id;
            if ($action === 'submit' && $locked->status === 'draft') {
                $updates += ['status' => 'submitted', 'submitted_by' => $actor, 'submitted_at' => now()];
            } elseif ($action === 'verify' && $locked->status === 'submitted') {
                if ((int) $locked->prepared_by === (int) $actor) {
                    throw new RegistryConflictException('The preparer cannot verify the same remittance.');
                } $updates += ['status' => abs((float) $locked->difference_amount) > 0 ? 'with_variance' : 'verified', 'verified_by' => $actor, 'verified_at' => now()];
            } elseif ($action === 'accept' && in_array($locked->status, ['verified', 'with_variance'], true)) {
                $transfer = $locked->destination_cash_account_id ? $this->postRemittanceTransfer($locked, $company, $request) : null;
                $updates += ['status' => 'accepted', 'accepted_by' => $actor, 'accepted_at' => now(), 'cash_transfer_document_id' => $transfer?->id, 'transfer_posted_at' => $transfer?->posted_at ?? ($transfer ? now() : null)];
            } elseif ($action === 'reverse' && $locked->status === 'accepted') {
                $this->requireReason($input['reason'] ?? null);
                if ($locked->transfer) {
                    $this->transfers->reverse($locked->transfer, (string) $input['reason'], $company, $request);
                }
                $updates += ['status' => 'reversed', 'reversed_by' => $actor, 'reversed_at' => now(), 'reversal_reason' => $input['reason']];
            } elseif ($action === 'reject' && in_array($locked->status, ['submitted', 'verified', 'with_variance'], true)) {
                $updates += ['status' => 'rejected', 'reason' => $input['reason'] ?? null];
            } else {
                throw new RegistryConflictException('The remittance cannot take this action from its current status.');
            }
            $locked->update($updates);
            if ($action === 'verify' && abs((float) $locked->difference_amount) > 0) {
                RemittanceVariance::firstOrCreate(['cash_remittance_id' => $locked->id], ['id' => (string) Str::uuid(), 'company_id' => $company->id, 'expected_amount' => $locked->expected_amount, 'actual_amount' => $locked->submitted_amount, 'difference_amount' => $locked->difference_amount, 'status' => 'open', 'reason' => $input['reason'] ?? null, 'evidence_reference' => $input['evidence_reference'] ?? $locked->evidence_reference, 'owner_id' => $locked->collector_id, 'correlation_id' => $request->attributes->get('correlation_id')]);
            }
            if ($action === 'accept') {
                foreach ($locked->lines as $line) {
                    $line->tender->update(['remittance_status' => 'remitted', 'version' => $line->tender->version + 1]);
                }
            } elseif ($action === 'reverse') {
                foreach ($locked->lines as $line) {
                    $line->tender->update(['remittance_status' => 'unremitted', 'version' => $line->tender->version + 1]);
                }
            }
            $this->audit->record($request, 'collections.remittance.'.$action, $locked, $company->id, ['status' => $from], $locked->toArray(), $input['reason'] ?? null, 'Cash remittance '.$action, 'Cash remittance segregation and variance state was updated.');

            return $locked->refresh();
        });

        $eventName = match ($action) {
            'submit' => 'remittance.submitted',
            'verify' => 'remittance.verified',
            'accept' => 'remittance.accepted',
            'reverse' => 'remittance.reversed',
            default => null,
        };
        if ($eventName) {
            $this->event($eventName, $result, $company, $request, $result->remittance_date?->toDateString());
            if ($action === 'verify' && abs((float) $result->difference_amount) > 0) {
                $this->event('remittance.variance.identified', $result, $company, $request, $result->remittance_date?->toDateString());
            }
            if ($action === 'accept' && $result->cash_transfer_document_id) {
                $this->event('remittance.posted', $result, $company, $request, $result->remittance_date?->toDateString());
            }
        }

        return $result->load(['lines.tender.receipt', 'variances', 'sourceAccount', 'destinationAccount', 'transfer.legs.movement']);
    }

    public function remittances(Company $company, Request $request): array
    {
        $page = CashRemittance::where('company_id', $company->id)->with(['lines.tender.receipt', 'variances', 'sourceAccount', 'destinationAccount', 'transfer.legs'])->latest('remittance_date')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->getCollection(), ['pagination' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]];
    }

    public function showRemittance(string $id, Company $company): CashRemittance
    {
        return CashRemittance::where('company_id', $company->id)->whereKey($id)->with(['lines.tender.receipt', 'variances', 'sourceAccount', 'destinationAccount', 'transfer.legs.movement'])->firstOrFail();
    }

    public function resolveVariance(string $id, array $input, Company $company, Request $request): RemittanceVariance
    {
        if (! trim((string) ($input['resolution'] ?? ''))) {
            throw new RegistryConflictException('A remittance variance resolution is required.');
        }
        $variance = DB::transaction(function () use ($id, $input, $company, $request) {
            $variance = RemittanceVariance::where('company_id', $company->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($variance->status, ['open', 'under_review', 'explained'], true)) {
                throw new RegistryConflictException('This remittance variance is no longer open for resolution.');
            }
            $variance->update(['status' => 'approved', 'resolution' => $input['resolution'], 'evidence_reference' => $input['evidence_reference'] ?? $variance->evidence_reference, 'approved_by' => $request->user()?->id, 'approved_at' => now(), 'version' => $variance->version + 1]);
            $this->audit->record($request, 'collections.remittance.variance.resolved', $variance, $company->id, [], $variance->toArray(), $input['resolution'], 'Remittance variance resolved', 'A remittance variance explanation and evidence reference were recorded.');
            $this->event('remittance.variance.resolved', $variance, $company, $request, $variance->remittance?->remittance_date?->toDateString());

            return $variance->refresh();
        });

        return $variance->load('remittance');
    }

    public function report(string $report, Company $company, Request $request): array
    {
        $allowed = ['daily_collections', 'receipt_register', 'collection_history', 'unapplied', 'payment_method_summary', 'remittance_register', 'remittance_variance', 'other_receipts'];
        if (! in_array($report, $allowed, true)) {
            throw new RegistryConflictException('The requested Collections report is not available.');
        }
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();
        $receipts = Receipt::where('company_id', $company->id)->when($from, fn ($q) => $q->whereDate('receipt_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('receipt_date', '<=', $to));

        return match ($report) {
            'daily_collections', 'receipt_register' => ['report' => $report, 'rows' => (clone $receipts)->whereIn('status', ['posted', 'reversed', 'failed'])->with(['customer', 'currency', 'tenders'])->latest('receipt_date')->get()->map(fn ($receipt) => ['id' => $receipt->id, 'receipt_number' => $receipt->receipt_number, 'date' => $receipt->receipt_date?->toDateString(), 'customer' => $receipt->customer?->display_name, 'type' => $receipt->receipt_type, 'amount' => (string) $receipt->amount, 'status' => $receipt->status, 'tenders' => $receipt->tenders->map(fn ($tender) => ['method' => $tender->paymentMethod?->name, 'amount' => (string) $tender->amount])])],
            'collection_history' => ['report' => $report, 'rows' => ReceiptStatusHistory::where('company_id', $company->id)->with('receipt')->latest()->get()->map(fn ($history) => ['receipt_id' => $history->receipt_id, 'receipt_number' => $history->receipt?->receipt_number, 'from' => $history->from_status, 'to' => $history->to_status, 'reason' => $history->reason, 'created_at' => $history->created_at?->toISOString()])],
            'unapplied' => ['report' => $report, 'rows' => CustomerUnappliedReceipt::where('company_id', $company->id)->with(['customer', 'receipt'])->get()->map(fn ($item) => ['receipt_id' => $item->receipt_id, 'receipt_number' => $item->receipt?->receipt_number, 'customer' => $item->customer?->display_name, 'available_amount' => (string) $item->available_amount, 'status' => $item->status])],
            'payment_method_summary' => ['report' => $report, 'rows' => ReceiptTender::where('company_id', $company->id)->whereHas('receipt', fn ($q) => $q->where('status', 'posted'))->with('paymentMethod')->get()->groupBy('payment_method_id')->map(fn ($group) => ['payment_method_id' => $group->first()->payment_method_id, 'payment_method' => $group->first()->paymentMethod?->name, 'count' => $group->count(), 'amount' => (string) $group->sum('amount')])->values()],
            'remittance_register' => ['report' => $report, 'rows' => CashRemittance::where('company_id', $company->id)->with('variances')->latest('remittance_date')->get()],
            'remittance_variance' => ['report' => $report, 'rows' => RemittanceVariance::where('company_id', $company->id)->with('remittance')->latest()->get()],
            'other_receipts' => ['report' => $report, 'rows' => (clone $receipts)->where('receipt_type', 'other_receipt')->get()],
        };
    }

    private function postRemittanceTransfer(CashRemittance $remittance, Company $company, Request $request): CashTransferDocument
    {
        if ($remittance->cash_transfer_document_id) {
            throw new RegistryConflictException('This Remittance is already linked to an MDS-700 Transfer.');
        }
        if ($remittance->status === 'with_variance' && ! $remittance->variances->contains(fn ($variance) => $variance->status === 'approved')) {
            throw new RegistryConflictException('Resolve the Remittance variance before posting its MDS-700 Transfer.', ['variance' => true]);
        }
        if ((float) $remittance->submitted_amount <= 0) {
            throw new RegistryConflictException('A Remittance Transfer requires a positive approved actual amount.');
        }

        $sourceId = $remittance->source_cash_account_id ?: $remittance->lines->pluck('tender.cash_account_id')->filter()->unique()->first();
        $destinationId = $remittance->destination_cash_account_id;
        $source = $sourceId ? CashAccount::where('company_id', $company->id)->whereKey($sourceId)->with('currency')->first() : null;
        $destination = $destinationId ? CashAccount::where('company_id', $company->id)->whereKey($destinationId)->with('currency')->first() : null;
        if (! $source || ! $destination) {
            throw new RegistryConflictException('The Remittance source and destination Cash Accounts must belong to the current company.');
        }
        if ((string) $source->currency_id !== (string) $destination->currency_id || (string) $source->currency_id !== (string) $remittance->currency_id) {
            throw new RegistryConflictException('Remittance Transfer accounts must use the Remittance currency.');
        }
        if ((string) $source->id === (string) $destination->id) {
            throw new RegistryConflictException('The Remittance source and destination Cash Accounts must be different.');
        }
        $reason = ReasonCode::where('company_id', $company->id)->where('domain', 'TRANSFER')->where('status', 'active')->orderBy('code')->first();
        if (! $reason) {
            throw new RegistryConflictException('An active TRANSFER Reason Code is required before a Remittance can post.', ['dependency' => 'reason_code']);
        }

        $transfer = $this->transfers->create([
            'purpose' => 'INTERNAL_TRANSFER',
            'source_cash_account_id' => $source->id,
            'destination_cash_account_id' => $destination->id,
            'currency_id' => $remittance->currency_id,
            'amount' => $remittance->submitted_amount,
            'business_date' => $remittance->remittance_date?->toDateString(),
            'reason_code_id' => $reason->id,
            'external_reference' => $remittance->remittance_number,
            'explanation' => 'MDS-300 Cash Remittance '.$remittance->remittance_number.' transfer between company Cash Accounts.',
            'supporting_reference' => $remittance->evidence_reference ?: $remittance->deposit_reference,
        ], $company, $request);

        $preparedBy = $remittance->prepared_by ?: $transfer->prepared_by;
        if ((int) $preparedBy === (int) $request->user()?->id) {
            throw new RegistryConflictException('A Remittance Transfer requires a different approving Cash Account authority from its preparer.', ['segregation' => true]);
        }
        $transfer->update(['prepared_by' => $preparedBy, 'status' => 'submitted', 'submitted_by' => $request->user()?->id, 'submitted_at' => now(), 'submitted_version' => $transfer->version + 1, 'version' => $transfer->version + 1]);
        $transfer = $this->transfers->approve($transfer->refresh(), $company, $request);

        return $this->transfers->post($transfer, $company, $request);
    }

    private function ensureOtherReceiptTypes(Company $company): void
    {
        $types = [
            ['OWNER_CONTRIBUTION', 'Owner contribution', 'equity', null, true, false],
            ['LOAN_PROCEEDS', 'Loan proceeds', 'liability', null, true, true],
            ['DEPOSIT_REFUND', 'Deposit refund', 'other_income', null, true, true],
            ['INTEREST_INCOME', 'Interest income', 'income', null, true, true],
            ['INSURANCE_PROCEEDS', 'Insurance proceeds', 'other_income', null, true, true],
            ['ASSET_SALE_PROCEEDS', 'Asset sale proceeds', 'other_income', 'asset', true, true],
            ['OTHER_AUTHORIZED_INFLOW', 'Other authorized inflow', 'other_income', null, true, true],
        ];
        foreach ($types as [$code, $name, $classification, $sourceModule, $requiresCounterparty, $requiresReference]) {
            OtherReceiptType::firstOrCreate(['company_id' => $company->id, 'code' => $code], ['id' => (string) Str::uuid(), 'name' => $name, 'classification' => $classification, 'source_module' => $sourceModule, 'requires_counterparty' => $requiresCounterparty, 'requires_source_reference' => $requiresReference, 'requires_approval' => true, 'active' => true, 'version' => 1]);
        }
    }

    private function validateHeader(array $input, Company $company): void
    {
        $customer = BusinessPartner::where('company_id', $company->id)->whereKey($input['customer_id'] ?? null)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'customer')->where('status', 'active'))->first();
        $currency = ReferenceCurrency::where('company_id', $company->id)->whereKey($input['currency_id'] ?? null)->where('status', 'active')->first();
        if (! $customer) {
            throw new RegistryConflictException('An active same-company customer is required.');
        }
        if (! $currency) {
            throw new RegistryConflictException('An active same-company receipt currency is required.');
        }
        if (! empty($input['source_sale_id']) && ! Sale::where('company_id', $company->id)->whereKey($input['source_sale_id'])->where('customer_id', $customer->id)->where('currency_id', $currency->id)->where('status', 'posted')->exists()) {
            throw new RegistryConflictException('The source Sale must be a posted same-customer, same-currency Sale.');
        }
    }

    private function replaceLines(Receipt $receipt, array $input, Company $company, Request $request): void
    {
        $tenderTotal = '0';
        foreach ($input['tenders'] as $tender) {
            $method = PaymentMethod::where('company_id', $company->id)->whereKey($tender['payment_method_id'])->where('status', 'active')->where('supports_incoming', true)->first();
            $account = CashAccount::where('company_id', $company->id)->whereKey($tender['cash_account_id'])->where('status', 'active')->where('currency_id', $receipt->currency_id)->whereHas('capabilities', fn ($q) => $q->where('capability', 'RECEIVE_FUNDS')->where('enabled', true))->first();
            if (! $method || ! $account) {
                throw new RegistryConflictException('Each tender must use an active incoming Payment Method and receiving Cash Account.');
            } if ($method->requires_external_reference && empty($tender['external_reference'])) {
                throw new RegistryConflictException('An external reference is required for the selected Payment Method.');
            } $tenderTotal = bcadd($tenderTotal, (string) $tender['amount'], 6);
            $receipt->tenders()->create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payment_method_id' => $method->id, 'cash_account_id' => $account->id, 'currency_id' => $receipt->currency_id, 'amount' => $tender['amount'], 'instrument_status' => 'not_applicable', 'clearing_status' => $method->clearing_behavior === 'direct' ? 'not_applicable' : 'pending', 'external_reference' => $tender['external_reference'] ?? null, 'instrument_reference' => $tender['instrument_reference'] ?? null, 'value_date' => $tender['value_date'] ?? null, 'notes' => $tender['notes'] ?? null, 'created_by' => $request->user()?->id]);
        }
        if ($receipt->receipt_type === 'other_receipt') {
            if (! empty($input['applications'])) {
                throw new RegistryConflictException('An Other Receipt cannot settle customer receivables.');
            }
            $receipt->update(['tender_total' => $tenderTotal, 'applied_total' => '0', 'unapplied_amount' => '0', 'application_status' => 'not_applicable']);

            return;
        }
        $appliedTotal = '0';
        foreach ($input['applications'] ?? [] as $application) {
            $item = ReceivableOpenItem::where('company_id', $company->id)->whereKey($application['receivable_open_item_id'])->where('customer_id', $receipt->customer_id)->where('currency_id', $receipt->currency_id)->where('remaining_amount', '>', 0)->first();
            if (! $item) {
                throw new RegistryConflictException('Each application must target an available same-customer, same-currency open item.');
            } $appliedTotal = bcadd($appliedTotal, (string) $application['amount'], 6);
            $receipt->applications()->create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'customer_id' => $receipt->customer_id, 'receivable_open_item_id' => $item->id, 'currency_id' => $receipt->currency_id, 'amount' => $application['amount'], 'application_date' => $receipt->receipt_date, 'status' => 'unapplied', 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
        }
        if (bccomp($appliedTotal, (string) $receipt->amount, 6) > 0) {
            throw new RegistryConflictException('Applications cannot exceed the receipt amount.');
        }
        $unapplied = bcsub((string) $receipt->amount, $appliedTotal, 6);
        $receipt->update(['tender_total' => $tenderTotal, 'applied_total' => $appliedTotal, 'unapplied_amount' => $unapplied, 'application_status' => (bccomp($appliedTotal, '0', 6) === 0 ? 'unapplied' : (bccomp($unapplied, '0', 6) === 0 ? 'fully_applied' : 'partially_applied'))]);
    }

    private function validateReady(Receipt $receipt, Company $company): void
    {
        if ((float) $receipt->amount <= 0 || (float) $receipt->tender_total <= 0) {
            throw new RegistryConflictException('A receipt must have a positive amount and at least one tender.');
        } if (bccomp((string) $receipt->tender_total, (string) $receipt->amount, 6) !== 0) {
            throw new RegistryConflictException('Tender total must equal the receipt amount.');
        }
    }

    private function validateTotals(Receipt $receipt, $tenders, $applications): void
    {
        $tenderTotal = (string) $tenders->sum('amount');
        $appTotal = (string) $applications->sum('amount');
        if ($receipt->receipt_type === 'other_receipt') {
            if (bccomp($tenderTotal, (string) $receipt->amount, 6) !== 0 || bccomp($appTotal, '0', 6) !== 0) {
                throw new RegistryConflictException('Other Receipt tender totals must equal the receipt amount and cannot contain receivable applications.');
            }

            return;
        }
        if (bccomp($tenderTotal, (string) $receipt->amount, 6) !== 0 || bccomp($appTotal, (string) $receipt->applied_total, 6) !== 0 || bccomp(bcadd($appTotal, (string) $receipt->unapplied_amount, 6), (string) $receipt->amount, 6) !== 0) {
            throw new RegistryConflictException('Receipt totals are inconsistent. Refresh and review the draft.');
        }
    }

    private function validateApplication(Receipt $receipt, ReceivableOpenItem $item, string $amount): void
    {
        if ((string) $receipt->customer_id !== (string) $item->customer_id || (string) $receipt->currency_id !== (string) $item->currency_id || bccomp($amount, (string) $item->remaining_amount, 6) > 0) {
            throw new RegistryConflictException('The payment application does not match the customer, currency, or available open-item balance.');
        }
    }

    private function postingAccount(Company $company, string $classification, array $terms): AccountTitle
    {
        $account = AccountTitle::where('company_id', $company->id)->where('classification', $classification)->where('status', 'active')->where('posting_eligible', true)->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(code) LIKE ?', ['%'.$term.'%']);
            }
        })->first();
        if (! $account) {
            throw new RegistryConflictException('Collections posting is blocked until a matching active posting Account Title is configured.', ['dependency' => 'account_titles', 'classification' => $classification]);
        }

        return $account;
    }

    private function otherReceiptPostingAccount(Company $company, string $classification): AccountTitle
    {
        $terms = match ($classification) {
            'equity' => ['capital', 'owner', 'contribution'],
            'liability' => ['loan', 'payable', 'liability'],
            'income' => ['interest', 'income'],
            default => ['other income', 'other', 'miscellaneous'],
        };

        return $this->postingAccount($company, $classification === 'other_income' ? 'income' : $classification, $terms);
    }

    private function history(Receipt $receipt, ?string $from, string $to, Company $company, Request $request, ?string $reason = null): void
    {
        ReceiptStatusHistory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_id' => $receipt->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $receipt->version ?: 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function applicationHistory(PaymentApplication $app, ?string $from, string $to, Company $company, Request $request, ?string $reason = null): void
    {
        PaymentApplicationHistory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'payment_application_id' => $app->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'version' => $app->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function scope(Receipt $receipt, Company $company): void
    {
        if ((int) $receipt->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The receipt is outside the current company scope.');
        }
    }

    private function version(Receipt $receipt, array $input): void
    {
        if (isset($input['version']) && (int) $input['version'] !== (int) $receipt->version) {
            throw new RegistryConflictException('This receipt was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
    }

    private function requireReason(?string $reason): void
    {
        if (! trim((string) $reason)) {
            throw new RegistryConflictException('A reason is required for this correction.');
        }
    }

    private function safe(Receipt $receipt): array
    {
        return ['id' => $receipt->id, 'receipt_number' => $receipt->receipt_number, 'status' => $receipt->status, 'amount' => (string) $receipt->amount, 'application_status' => $receipt->application_status];
    }

    private function event(string $name, Model $record, Company $company, Request $request, ?string $businessDate = null): void
    {
        CollectionsLifecycleEvent::dispatch(
            $name,
            (int) $company->id,
            $record::class,
            (string) $record->getKey(),
            $request->user()?->id,
            $request->attributes->get('correlation_id'),
            $businessDate,
        );
    }

    private function applicationAccounting(Company $company, Request $request, string $businessDate, string $amount, bool $reverse): void
    {
        $receivable = $this->postingAccount($company, 'asset', ['receivable']);
        $advance = $this->postingAccount($company, 'liability', ['advance', 'deposit', 'unapplied', 'customer']);
        $businessId = (string) Str::uuid();
        $type = $reverse ? 'customer_application_reversal' : 'customer_application';
        DB::table('business_transactions')->insert(['id' => $businessId, 'company_id' => $company->id, 'transaction_type' => $type, 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $businessDate, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key'), 'created_at' => now(), 'updated_at' => now()]);
        $accountingId = (string) Str::uuid();
        DB::table('accounting_transactions')->insert(['id' => $accountingId, 'business_transaction_id' => $businessId, 'company_id' => $company->id, 'transaction_type' => $type, 'status' => 'posted', 'business_date' => $businessDate, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
        $debit = $reverse ? $receivable : $advance;
        $credit = $reverse ? $advance : $receivable;
        DB::table('accounting_transaction_lines')->insert([['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accountingId, 'account_title_id' => $debit->id, 'debit' => $amount, 'credit' => '0', 'currency_code' => $company->currency, 'description' => ucfirst(str_replace('_', ' ', $type)).' debit effect', 'created_at' => now(), 'updated_at' => now()], ['id' => (string) Str::uuid(), 'accounting_transaction_id' => $accountingId, 'account_title_id' => $credit->id, 'debit' => '0', 'credit' => $amount, 'currency_code' => $company->currency, 'description' => ucfirst(str_replace('_', ' ', $type)).' credit effect', 'created_at' => now(), 'updated_at' => now()]]);
    }

    private function reverse(Receipt $receipt, ?string $reason, Company $company, Request $request): Receipt
    {
        $this->requireReason($reason);
        if ($receipt->status !== 'posted') {
            throw new RegistryConflictException('Only posted receipts may be reversed.');
        }

        $result = DB::transaction(function () use ($receipt, $reason, $company, $request) {
            $locked = Receipt::whereKey($receipt->id)->where('company_id', $company->id)->lockForUpdate()->with('tenders')->firstOrFail();
            if ($locked->status !== 'posted') {
                throw new RegistryConflictException('This receipt has already been reversed or is no longer posted.');
            }
            $activeApplications = $locked->applications()->whereIn('status', ['unapplied', 'partially_applied', 'fully_applied'])->lockForUpdate()->get();
            foreach ($activeApplications as $application) {
                $item = ReceivableOpenItem::whereKey($application->receivable_open_item_id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
                $item->applied_amount = bcsub((string) $item->applied_amount, (string) $application->amount, 6);
                $item->remaining_amount = bcadd((string) $item->remaining_amount, (string) $application->amount, 6);
                $item->settlement_status = (float) $item->applied_amount <= 0 ? 'unpaid' : 'partially_paid';
                $item->version++;
                $item->last_calculated_at = now();
                $item->save();
                if ($item->source_sale_id) {
                    $sourceSale = Sale::whereKey($item->source_sale_id)->where('company_id', $company->id)->lockForUpdate()->first();
                    if ($sourceSale) {
                        $sourceSale->paid_amount = bcsub((string) $sourceSale->paid_amount, (string) $application->amount, 6);
                        if (bccomp((string) $sourceSale->paid_amount, '0', 6) < 0) {
                            $sourceSale->paid_amount = '0';
                        }
                        $sourceSale->remaining_amount = bcadd((string) $sourceSale->remaining_amount, (string) $application->amount, 6);
                        $sourceSale->settlement_status = $item->settlement_status;
                        $sourceSale->version++;
                        $sourceSale->save();
                    }
                }
                $application->update(['status' => 'reversed', 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'version' => $application->version + 1]);
                PaymentApplication::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_id' => $application->receipt_id, 'customer_id' => $application->customer_id, 'receivable_open_item_id' => $application->receivable_open_item_id, 'currency_id' => $application->currency_id, 'amount' => $application->amount, 'application_date' => now()->toDateString(), 'status' => 'reversed', 'original_application_id' => $application->id, 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
                $this->applicationHistory($application, 'fully_applied', 'reversed', $company, $request, $reason);
            }
            $currency = ReferenceCurrency::whereKey($locked->currency_id)->where('company_id', $company->id)->firstOrFail();
            $isOtherReceipt = $locked->receipt_type === 'other_receipt';
            $advance = ! $isOtherReceipt ? $this->postingAccount($company, 'liability', ['advance', 'deposit', 'unapplied', 'customer']) : null;
            $otherTitle = $isOtherReceipt ? $this->otherReceiptPostingAccount($company, (string) $locked->classification) : null;
            $businessId = (string) Str::uuid();
            $reversalType = $isOtherReceipt ? 'other_receipt_reversal' : 'customer_receipt_reversal';
            DB::table('business_transactions')->insert(['id' => $businessId, 'company_id' => $company->id, 'transaction_type' => $reversalType, 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => now()->toDateString(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key'), 'created_at' => now(), 'updated_at' => now()]);
            $accountingId = (string) Str::uuid();
            DB::table('accounting_transactions')->insert(['id' => $accountingId, 'business_transaction_id' => $businessId, 'company_id' => $company->id, 'transaction_type' => $reversalType, 'status' => 'posted', 'business_date' => now()->toDateString(), 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
            $remainingApplied = (string) $locked->applied_total;
            foreach ($locked->tenders()->orderBy('id')->lockForUpdate()->get() as $tender) {
                $account = CashAccount::whereKey($tender->cash_account_id)->where('company_id', $company->id)->with('currency')->lockForUpdate()->firstOrFail();
                $method = PaymentMethod::whereKey($tender->payment_method_id)->where('company_id', $company->id)->where('status', 'active')->firstOrFail();
                $movement = CashMovement::where('company_id', $company->id)->where('source_record_type', Receipt::class)->where('source_record_id', $locked->id)->where('cash_account_id', $account->id)->where('amount', $tender->amount)->where('movement_status', 'posted')->whereNull('reversal_movement_id')->lockForUpdate()->firstOrFail();
                $appliedPart = min((float) $remainingApplied, (float) $tender->amount);
                $unappliedPart = (float) $tender->amount - $appliedPart;
                $offsets = [];
                if ($isOtherReceipt) {
                    $offsets[] = ['account_title_id' => $otherTitle->id, 'amount' => (string) $tender->amount, 'description' => 'Other Receipt reversal restoration'];
                } else {
                    if ($appliedPart > 0) {
                        $offsets[] = ['account_title_id' => $this->postingAccount($company, 'asset', ['receivable'])->id, 'amount' => number_format($appliedPart, 6, '.', ''), 'description' => 'Receipt reversal receivable restoration'];
                    }
                    if ($unappliedPart > 0) {
                        $offsets[] = ['account_title_id' => $advance->id, 'amount' => number_format($unappliedPart, 6, '.', ''), 'description' => 'Receipt reversal customer advance restoration'];
                    }
                }
                $reversal = $this->cash->createReceiptReversalEffectWithOffsets($account, $company, (string) $tender->amount, $currency->code, now()->toDateString(), $accountingId, $offsets, $locked, $method, $movement, $request);
                $movement->update(['reversal_movement_id' => $reversal->id]);
                $remainingApplied = bcsub($remainingApplied, (string) $appliedPart, 6);
            }
            if ($unapplied = CustomerUnappliedReceipt::where('receipt_id', $locked->id)->lockForUpdate()->first()) {
                $unapplied->available_amount = '0';
                $unapplied->status = 'reversed';
                $unapplied->version++;
                $unapplied->save();
            }
            $locked->update(['status' => 'reversed', 'reversed_by' => $request->user()?->id, 'reversed_at' => now(), 'reversed_amount' => $locked->amount, 'correction_reason' => $reason, 'accounting_transaction_id' => $accountingId, 'business_transaction_id' => $businessId, 'version' => $locked->version + 1]);
            $this->history($locked, 'posted', 'reversed', $company, $request, $reason);

            return $locked->refresh();
        });
        $this->audit->record($request, 'collections.receipt.reversed', $result, $company->id, [], $this->safe($result), $reason, 'Receipt reversed', 'A posted receipt was reversed with linked MDS-700 and accounting counter-effects.');
        $this->event('receipt.reversed', $result, $company, $request, $result->receipt_date?->toDateString());

        return $this->load($result);
    }
}
