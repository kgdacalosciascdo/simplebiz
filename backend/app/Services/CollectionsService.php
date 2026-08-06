<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\BusinessPartner;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\CustomerUnappliedReceipt;
use App\Models\PaymentApplication;
use App\Models\PaymentApplicationHistory;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\ReceiptStatusHistory;
use App\Models\ReceivableOpenItem;
use App\Models\ReferenceCurrency;
use App\Models\Sale;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CollectionsService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers, private readonly CashMovementService $cash) {}

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
        $query = Receipt::where('company_id', $company->id)->with(['customer', 'currency'])->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('receipt_number', 'ilike', '%'.$request->string('q').'%')->orWhere('external_reference', 'ilike', '%'.$request->string('q').'%')->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'ilike', '%'.$request->string('q').'%'))))->latest('receipt_date')->latest('created_at');
        $perPage = min((int) $request->input('per_page', 25), 100);
        $items = $query->paginate($perPage);

        return [$items->getCollection(), ['pagination' => ['total' => $items->total(), 'per_page' => $items->perPage(), 'current_page' => $items->currentPage(), 'last_page' => $items->lastPage()]]];
    }

    public function createDraft(array $input, Company $company, Request $request): Receipt
    {
        $this->validateHeader($input, $company);
        $receipt = DB::transaction(function () use ($input, $company, $request) {
            $receipt = Receipt::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_number' => $this->numbers->next($company->id, 'receipt'), 'receipt_type' => $input['receipt_type'], 'receipt_date' => $input['receipt_date'], 'customer_id' => $input['customer_id'], 'source_sale_id' => $input['source_sale_id'] ?? null, 'currency_id' => $input['currency_id'], 'payer_name_snapshot' => $input['payer_name_snapshot'] ?? null, 'external_reference' => $input['external_reference'] ?? null, 'customer_reference' => $input['customer_reference'] ?? null, 'amount' => $input['amount'], 'status' => 'draft', 'application_status' => 'unapplied', 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key')]);
            $this->replaceLines($receipt, $input, $company, $request);
            $this->history($receipt, null, 'draft', $company, $request);

            return $receipt->refresh();
        });
        $this->audit->record($request, 'collections.receipt.drafted', $receipt, $company->id, [], $this->safe($receipt), null, 'Receipt drafted', 'A customer collection receipt draft was created.');

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
            $receivableTitle = $this->postingAccount($company, 'asset', ['receivable']);
            $advanceTitle = $locked->unapplied_amount > 0 ? $this->postingAccount($company, 'liability', ['advance', 'deposit', 'unapplied', 'customer']) : null;
            $businessId = (string) Str::uuid();
            DB::table('business_transactions')->insert(['id' => $businessId, 'company_id' => $company->id, 'transaction_type' => 'customer_receipt', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $locked->receipt_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key'), 'created_at' => now(), 'updated_at' => now()]);
            $accountingId = (string) Str::uuid();
            DB::table('accounting_transactions')->insert(['id' => $accountingId, 'business_transaction_id' => $businessId, 'company_id' => $company->id, 'transaction_type' => 'customer_receipt', 'status' => 'posted', 'business_date' => $locked->receipt_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
            $remainingApplied = (string) $locked->applied_total;
            foreach ($tenders as $tender) {
                $appliedPart = min((float) $remainingApplied, (float) $tender->amount);
                $unappliedPart = (float) $tender->amount - $appliedPart;
                $offsets = [];
                if ($appliedPart > 0) {
                    $offsets[] = ['account_title_id' => $receivableTitle->id, 'amount' => number_format($appliedPart, 6, '.', ''), 'description' => 'Customer Receipt applied to receivable'];
                }
                if ($unappliedPart > 0) {
                    $offsets[] = ['account_title_id' => $advanceTitle->id, 'amount' => number_format($unappliedPart, 6, '.', ''), 'description' => 'Customer Receipt customer advance'];
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
            if ((float) $locked->unapplied_amount > 0) {
                CustomerUnappliedReceipt::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'receipt_id' => $locked->id, 'customer_id' => $locked->customer_id, 'currency_id' => $locked->currency_id, 'original_amount' => $locked->unapplied_amount, 'available_amount' => $locked->unapplied_amount, 'received_date' => $locked->receipt_date, 'status' => 'available']);
            }
            $locked->update(['status' => 'posted', 'posted_by' => $request->user()?->id, 'posted_at' => now(), 'business_transaction_id' => $businessId, 'accounting_transaction_id' => $accountingId, 'version' => $locked->version + 1]);
            $this->history($locked, 'approved', 'posted', $company, $request);

            return $locked->refresh();
        });
        $this->audit->record($request, 'collections.receipt.posted', $result, $company->id, [], $this->safe($result), null, 'Receipt posted', 'Receipt, customer applications, MDS-700 cash effects, and balanced accounting were posted atomically.');

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
        return $receipt->load(['customer', 'currency', 'tenders.paymentMethod', 'tenders.cashAccount', 'applications.receivable', 'unapplied.customer', 'unapplied.currency', 'statusHistory']);
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
            if ($locked->applications()->whereIn('status', ['unapplied', 'partially_applied', 'fully_applied'])->exists()) {
                throw new RegistryConflictException('Reverse or reapply the receipt applications before reversing the receipt.');
            }
            $currency = ReferenceCurrency::whereKey($locked->currency_id)->where('company_id', $company->id)->firstOrFail();
            $advance = $this->postingAccount($company, 'liability', ['advance', 'deposit', 'unapplied', 'customer']);
            $businessId = (string) Str::uuid();
            DB::table('business_transactions')->insert(['id' => $businessId, 'company_id' => $company->id, 'transaction_type' => 'customer_receipt_reversal', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => now()->toDateString(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => $request->header('Idempotency-Key'), 'created_at' => now(), 'updated_at' => now()]);
            $accountingId = (string) Str::uuid();
            DB::table('accounting_transactions')->insert(['id' => $accountingId, 'business_transaction_id' => $businessId, 'company_id' => $company->id, 'transaction_type' => 'customer_receipt_reversal', 'status' => 'posted', 'business_date' => now()->toDateString(), 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($locked->tenders()->orderBy('id')->lockForUpdate()->get() as $tender) {
                $account = CashAccount::whereKey($tender->cash_account_id)->where('company_id', $company->id)->with('currency')->lockForUpdate()->firstOrFail();
                $method = PaymentMethod::whereKey($tender->payment_method_id)->where('company_id', $company->id)->where('status', 'active')->firstOrFail();
                $movement = CashMovement::where('company_id', $company->id)->where('source_record_type', Receipt::class)->where('source_record_id', $locked->id)->where('cash_account_id', $account->id)->where('amount', $tender->amount)->where('movement_status', 'posted')->whereNull('reversal_movement_id')->lockForUpdate()->firstOrFail();
                $reversal = $this->cash->createReceiptReversalEffect($account, $company, (string) $tender->amount, $currency->code, now()->toDateString(), $accountingId, $advance->id, $locked, $method, $movement, $request);
                $movement->update(['reversal_movement_id' => $reversal->id]);
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

        return $this->load($result);
    }
}
