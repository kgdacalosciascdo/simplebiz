<?php

namespace App\Services;

use App\Events\SalesLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\BillingStatement;
use App\Models\BusinessPartner;
use App\Models\BusinessTransaction;
use App\Models\Company;
use App\Models\PaymentTerm;
use App\Models\ProductService;
use App\Models\ReceivableOpenItem;
use App\Models\ReferenceCurrency;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleStatusHistory;
use App\Models\TaxCode;
use App\Support\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SalesService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers) {}

    public function list(Company $company, Request $request)
    {
        $query = Sale::where('company_id', $company->id)->with(['customer', 'currency'])->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('payment_basis'), fn ($q) => $q->where('payment_basis', $request->string('payment_basis')))->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('sale_number', 'ilike', '%'.$request->string('q').'%')->orWhere('customer_reference', 'ilike', '%'.$request->string('q').'%')->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'ilike', '%'.$request->string('q').'%'))));
        $page = $query->latest('sale_date')->latest('created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]];
    }

    public function lookups(Company $company): array
    {
        return [
            'customers' => BusinessPartner::where('company_id', $company->id)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'customer')->where('status', 'active'))->orderBy('display_name')->get(['id', 'code', 'display_name']),
            'items' => ProductService::where('company_id', $company->id)->where('status', 'active')->where('sellable', true)->with('baseUnit')->orderBy('name')->get(['id', 'code', 'name', 'record_type', 'base_unit_id', 'stock_managed', 'non_stock', 'standard_selling_price', 'tax_reference']),
            'currencies' => ReferenceCurrency::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'symbol', 'decimal_precision']),
            'payment_terms' => PaymentTerm::where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name', 'term_type', 'due_days', 'end_of_month']),
            'tax_codes' => TaxCode::where('company_id', $company->id)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'tax_type', 'rate', 'basis']),
        ];
    }

    public function summary(Company $company): array
    {
        $sales = Sale::where('company_id', $company->id);
        $receivables = ReceivableOpenItem::where('company_id', $company->id);

        return ['sales' => ['total' => (clone $sales)->count(), 'draft' => (clone $sales)->where('status', 'draft')->count(), 'awaiting_review' => (clone $sales)->where('status', 'for_approval')->count(), 'approved' => (clone $sales)->where('status', 'approved')->count(), 'posted' => (clone $sales)->where('status', 'posted')->count(), 'blocked' => (clone $sales)->where('status', 'failed')->count()], 'receivables' => ['open_items' => (clone $receivables)->where('remaining_amount', '>', 0)->count(), 'overdue' => (clone $receivables)->where('remaining_amount', '>', 0)->where('due_status', 'overdue')->count(), 'due_today' => (clone $receivables)->where('remaining_amount', '>', 0)->where('due_status', 'due_today')->count()], 'recent_activity' => SaleStatusHistory::where('company_id', $company->id)->latest()->limit(10)->get(['id', 'sale_id', 'from_status', 'to_status', 'reason', 'created_at'])];
    }

    public function createDraft(array $input, Company $company, Request $request): Sale
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $sale = new Sale;
            $sale->id = (string) Str::uuid();
            $sale->company_id = $company->id;
            $sale->sale_number = $this->numbers->next($company->id, 'sale');
            $sale->sale_type = $input['sale_type'];
            $sale->payment_basis = $input['payment_basis'] ?? ($input['sale_type'] === 'cash_sale' ? 'cash' : 'credit');
            $sale->sale_date = $input['sale_date'];
            $sale->created_by = $request->user()?->id;
            $sale->prepared_at = now();
            $sale->status = 'draft';
            $sale->correlation_id = $request->attributes->get('correlation_id');
            $sale->idempotency_identity = $request->header('Idempotency-Key');
            $this->applyReferencesAndCalculate($sale, $input, $company, $request);
            $sale->save();
            $this->writeLines($sale, $input['lines'], $company, $request);
            $this->recalculateStoredSale($sale, $input, $company, $request);
            $this->audit($request, 'sales.draft.created', $sale, [], $sale->toArray(), 'A Sales draft was created.');
            SalesLifecycleEvent::dispatch('sales.draft.created', $company->id, 'sale', $sale->id);

            return $sale->fresh(['lines', 'customer', 'currency', 'paymentTerm']);
        });
    }

    public function updateDraft(Sale $sale, array $input, Company $company, Request $request): Sale
    {
        if ($sale->status !== 'draft') {
            throw new RegistryConflictException('Only Draft Sales can be edited.', ['status' => $sale->status]);
        }
        $this->assertVersion($sale, $input);

        return DB::transaction(function () use ($sale, $input, $company, $request) {
            $before = $sale->toArray();
            $sale->sale_date = $input['sale_date'] ?? $sale->sale_date;
            $this->applyReferencesAndCalculate($sale, $input + ['lines' => $sale->lines->toArray()], $company, $request);
            $sale->version++;
            $sale->save();
            if (array_key_exists('lines', $input)) {
                $sale->lines()->delete();
                $this->writeLines($sale, $input['lines'], $company, $request);
            }
            $this->recalculateStoredSale($sale, $input + ['lines' => $sale->lines->toArray()], $company, $request);
            $this->audit($request, 'sales.draft.updated', $sale, $before, $sale->toArray(), 'A Sales draft was updated.');

            return $sale->fresh(['lines', 'customer', 'currency', 'paymentTerm']);
        });
    }

    public function transition(Sale $sale, string $action, Company $company, Request $request, ?string $reason = null, ?int $version = null): Sale
    {
        try {
            return DB::transaction(function () use ($sale, $action, $company, $request, $reason, $version) {
                $sale = Sale::where('company_id', $company->id)->whereKey($sale->id)->with('lines')->lockForUpdate()->firstOrFail();
                if ($version !== null && $version !== (int) $sale->version) {
                    throw new RegistryConflictException('This Sale was changed by another user. Refresh and try again.', ['version_conflict' => true]);
                }
                $before = $sale->toArray();
                $actor = $request->user()?->id;
                $from = $sale->status;
                $to = match ($action) {
                    'submit' => $this->transitionTo($sale, 'draft', 'for_approval'),
                    'review' => $this->review($sale, $actor),
                    'return' => $this->returnForCorrection($sale, $actor, $reason),
                    'approve' => $this->approve($sale, $actor),
                    'post' => $this->post($sale, $company, $request),
                    'cancel' => $this->cancel($sale, $actor, $reason),
                    default => throw new RegistryConflictException('Unsupported Sales action.'),
                };
                if ($to !== null && $to !== $from) {
                    SaleStatusHistory::create(['id' => (string) Str::uuid(), 'sale_id' => $sale->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_id' => $actor, 'version' => $sale->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
                }
                $this->audit($request, 'sales.'.$action, $sale, $before, $sale->toArray(), $reason ?: 'Sales lifecycle action completed.');
                SalesLifecycleEvent::dispatch('sales.'.$action, $company->id, 'sale', $sale->id);

                return $sale->fresh(['lines', 'customer', 'currency', 'paymentTerm', 'receivable', 'statusHistory']);
            });
        } catch (RegistryConflictException $exception) {
            if ($action === 'post' && ! ($exception->errors['version_conflict'] ?? false) && isset($exception->errors['dependency'])) {
                $failed = Sale::where('company_id', $company->id)->whereKey($sale->id)->first();
                if ($failed) {
                    $from = $failed->status;
                    $failed->status = 'failed';
                    $failed->blocked_code = $exception->errors['dependency'];
                    $failed->blocked_reason = $exception->getMessage();
                    $failed->version++;
                    $failed->save();
                    SaleStatusHistory::create(['id' => (string) Str::uuid(), 'sale_id' => $failed->id, 'company_id' => $company->id, 'from_status' => $from, 'to_status' => 'failed', 'reason' => $exception->getMessage(), 'actor_id' => $request->user()?->id, 'version' => $failed->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
                    $this->audit($request, 'sales.post.failed', $failed, ['status' => $from], $failed->toArray(), $exception->getMessage());
                }
            }
            throw $exception;
        }
    }

    public function receivables(Company $company, Request $request)
    {
        $query = ReceivableOpenItem::where('company_id', $company->id)->with(['customer', 'sourceSale', 'currency'])->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')))->when($request->filled('status'), fn ($q) => $q->where('settlement_status', $request->string('status')))->when($request->filled('q'), fn ($q) => $q->where(fn ($search) => $search->where('source_document_number', 'ilike', '%'.$request->string('q').'%')->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'ilike', '%'.$request->string('q').'%'))));
        $page = $query->orderBy('due_date')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]];
    }

    public function aging(Company $company, Request $request): array
    {
        $items = ReceivableOpenItem::where('company_id', $company->id)->where('remaining_amount', '>', 0)->get();
        $today = Carbon::today($company->timezone ?: config('app.timezone'));
        $buckets = ['current' => '0', '1_30' => '0', '31_60' => '0', '61_90' => '0', 'over_90' => '0'];
        foreach ($items as $item) {
            if (! $item->due_date || $item->due_date->gte($today)) {
                $bucket = 'current';
            } else {
                $days = $item->due_date->diffInDays($today);
                $bucket = $days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90'));
            }
            $buckets[$bucket] = bcadd($buckets[$bucket], (string) $item->remaining_amount, 6);
        }

        return $buckets;
    }

    public function billingStatements(Company $company, Request $request)
    {
        $page = BillingStatement::where('company_id', $company->id)->with(['customer', 'currency'])->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')))->latest('statement_date')->paginate(min((int) $request->input('per_page', 25), 100));

        return [$page->items(), ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]];
    }

    public function createBillingStatement(array $input, Company $company, Request $request): BillingStatement
    {
        return DB::transaction(function () use ($input, $company, $request) {
            $customer = $this->customer($input['customer_id'], $company, false);
            $currency = $this->currency($input['currency_id'] ?? $company->default_currency_id, $company);
            $to = $input['period_to'] ?? $input['statement_date'];
            $from = $input['period_from'] ?? null;
            $items = ReceivableOpenItem::where('company_id', $company->id)->where('customer_id', $customer->id)->where('currency_id', $currency->id)->where('remaining_amount', '>', 0)->when($from, fn ($q) => $q->whereHas('sourceSale', fn ($sale) => $sale->whereDate('sale_date', '>=', $from)))->whereHas('sourceSale', fn ($sale) => $sale->whereDate('sale_date', '<=', $to))->with('sourceSale')->lockForUpdate()->get();
            $statement = BillingStatement::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'statement_number' => $this->numbers->next($company->id, 'billing_statement'), 'customer_id' => $customer->id, 'branch_id' => $input['branch_id'] ?? null, 'currency_id' => $currency->id, 'statement_date' => $input['statement_date'], 'period_from' => $from, 'period_to' => $to, 'as_of_at' => now(), 'status' => 'generated', 'opening_balance' => 0, 'period_charges' => $items->sum(fn ($i) => $i->sourceSale?->total ?? 0), 'period_credits' => 0, 'period_applications' => $items->sum('applied_amount'), 'ending_balance' => $items->sum('remaining_amount'), 'filters' => ['customer_id' => $customer->id, 'currency_id' => $currency->id, 'period_from' => $from, 'period_to' => $to], 'generated_by' => $request->user()?->id, 'generated_at' => now(), 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key'), 'version' => 1]);
            foreach ($items as $item) {
                $statement->openItems()->attach($item->id, ['source_sale_id' => $item->source_sale_id, 'included_amount' => $item->remaining_amount]);
            }
            $this->audit($request, 'sales.billing_statement.generated', $statement, [], $statement->toArray(), 'A billing statement was generated from existing receivable open items.');
            SalesLifecycleEvent::dispatch('sales.billing_statement.generated', $company->id, 'billing_statement', $statement->id);

            return $statement->fresh(['customer', 'currency', 'openItems.sourceSale']);
        });
    }

    private function applyReferencesAndCalculate(Sale $sale, array $input, Company $company, Request $request): void
    {
        $sale->sale_type = $input['sale_type'] ?? $sale->sale_type;
        $sale->payment_basis = $input['payment_basis'] ?? ($sale->sale_type === 'cash_sale' ? 'cash' : 'credit');
        if (($sale->sale_type === 'credit_sale' && $sale->payment_basis !== 'credit') || ($sale->sale_type === 'cash_sale' && $sale->payment_basis !== 'cash')) {
            throw new RegistryConflictException('Sale type and payment basis must agree.');
        }
        $sale->branch_id = $input['branch_id'] ?? $sale->branch_id;
        $sale->customer_id = $input['customer_id'] ?? $sale->customer_id;
        $sale->currency_id = $this->currency($input['currency_id'] ?? $sale->currency_id ?? $company->default_currency_id, $company)->id;
        $sale->payment_term_id = $sale->payment_basis === 'credit' ? $this->paymentTerm($input['payment_term_id'] ?? $sale->payment_term_id ?? $company->default_payment_term_id, $company)->id : null;
        $sale->customer_reference = $input['customer_reference'] ?? $sale->customer_reference;
        $sale->channel = $input['channel'] ?? $sale->channel;
        $sale->notes = $input['notes'] ?? $sale->notes;
        $sale->document_discount_type = $input['document_discount_type'] ?? null;
        $sale->document_discount_value = $input['document_discount_value'] ?? 0;
        if ($sale->payment_basis === 'credit') {
            $this->customer($sale->customer_id, $company, true);
            $term = PaymentTerm::find($sale->payment_term_id);
            $sale->due_date = $this->dueDate($sale->sale_date, $term, $input['due_date'] ?? null, $company);
        } else {
            $sale->customer_id = $input['customer_id'] ?? null;
            $sale->due_date = null;
        }
    }

    private function writeLines(Sale $sale, array $lines, Company $company, Request $request): void
    {
        foreach ($lines as $lineInput) {
            $item = ProductService::where('company_id', $company->id)->whereKey($lineInput['product_service_id'])->where('status', 'active')->where('sellable', true)->with('baseUnit')->first();
            if (! $item) {
                throw new RegistryConflictException('Every Sale line must reference an active same-company sellable Product or Service.', ['dependency' => 'product_service']);
            }
            $quantity = (string) $lineInput['quantity'];
            if (bccomp($quantity, '0', 6) <= 0) {
                throw new RegistryConflictException('Sale quantities must be positive.');
            }
            if ($item->baseUnit && ! $item->baseUnit->allows_fractional && bccomp($quantity, (string) (int) $quantity, 6) !== 0) {
                throw new RegistryConflictException('The selected Unit of Measure does not allow fractional quantities.', ['product_service_id' => $item->id]);
            }
            $hasExplicitPrice = array_key_exists('unit_price', $lineInput) && $lineInput['unit_price'] !== null;
            $unitPrice = $hasExplicitPrice ? (string) $lineInput['unit_price'] : (string) ($item->standard_selling_price ?? '0');
            if ($item->standard_selling_price === null && ! $hasExplicitPrice) {
                throw new RegistryConflictException('A standard selling price or an authorized price override is required.', ['dependency' => 'pricing']);
            }
            if ($hasExplicitPrice && $item->standard_selling_price !== null && bccomp($unitPrice, (string) $item->standard_selling_price, 6) !== 0 && (! $request->user()?->hasPermission('sales.price-override', $company->id) || empty($lineInput['price_override_reason']))) {
                throw new RegistryConflictException('A price override requires the Sales price-override permission and a reason.', ['dependency' => 'price_override']);
            }
            if (bccomp($unitPrice, '0', 6) < 0) {
                throw new RegistryConflictException('Sale prices cannot be negative.');
            }
            $gross = bcmul($quantity, $unitPrice, 6);
            $discountValue = (string) ($lineInput['discount_value'] ?? 0);
            $discountType = $lineInput['discount_type'] ?? null;
            $discount = $discountType === 'percent' ? bcdiv(bcmul($gross, $discountValue, 6), '100', 6) : ($discountType === 'amount' ? $discountValue : '0');
            if (bccomp($discount, $gross, 6) > 0) {
                throw new RegistryConflictException('A line discount cannot exceed the line amount.');
            }
            $tax = isset($lineInput['tax_code_id']) ? TaxCode::where('company_id', $company->id)->whereKey($lineInput['tax_code_id'])->where('status', 'active')->first() : null;
            if (isset($lineInput['tax_code_id']) && ! $tax) {
                throw new RegistryConflictException('The selected Tax Code is not active in this company.', ['dependency' => 'tax_code']);
            }
            $netBeforeTax = bcsub($gross, $discount, 6);
            $taxRate = $tax?->rate ?? '0';
            $taxAmount = $tax && $tax->basis === 'inclusive' ? bcsub($netBeforeTax, bcdiv($netBeforeTax, bcadd('1', bcdiv((string) $taxRate, '100', 6), 6), 6), 6) : ($tax ? bcdiv(bcmul($netBeforeTax, (string) $taxRate, 6), '100', 6) : '0');
            $net = $tax && $tax->basis === 'exclusive' ? bcadd($netBeforeTax, $taxAmount, 6) : $netBeforeTax;
            SaleLine::create(['id' => (string) Str::uuid(), 'sale_id' => $sale->id, 'company_id' => $company->id, 'product_service_id' => $item->id, 'item_type' => $item->record_type, 'item_code_snapshot' => $item->code, 'description_snapshot' => $lineInput['description'] ?? $item->name, 'unit_code' => $item->baseUnit?->code, 'unit_name' => $item->baseUnit?->name, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'gross_amount' => $gross, 'discount_type' => $discountType, 'discount_value' => $discountValue, 'discount_amount' => $discount, 'tax_code_id' => $tax?->id, 'tax_code_snapshot' => $tax?->code, 'tax_basis' => $tax?->basis, 'tax_rate' => $taxRate, 'taxable_amount' => $netBeforeTax, 'tax_amount' => $taxAmount, 'net_amount' => $net, 'stock_managed_snapshot' => (bool) $item->stock_managed, 'non_stock_snapshot' => (bool) $item->non_stock, 'service_snapshot' => $item->record_type === 'service', 'version' => 1]);
        }
    }

    private function recalculateStoredSale(Sale $sale, array $input, Company $company, Request $request): void
    {
        $lines = $sale->lines()->get();
        if ($lines->isEmpty()) {
            throw new RegistryConflictException('A Sale must contain at least one line.');
        }
        $subtotal = $lines->sum(fn ($line) => (string) $line->gross_amount);
        $lineDiscount = $lines->sum(fn ($line) => (string) $line->discount_amount);
        $beforeDocument = bcsub((string) $subtotal, (string) $lineDiscount, 6);
        $documentValue = (string) ($sale->document_discount_value ?? 0);
        $documentDiscount = $sale->document_discount_type === 'percent' ? bcdiv(bcmul($beforeDocument, $documentValue, 6), '100', 6) : ($sale->document_discount_type === 'amount' ? min($documentValue, $beforeDocument) : '0');
        $taxable = '0';
        $tax = '0';
        $total = '0';
        $allocated = '0';
        $last = $lines->count() - 1;
        foreach ($lines->values() as $index => $line) {
            $base = bcsub((string) $line->gross_amount, (string) $line->discount_amount, 6);
            $allocation = $index === $last ? bcsub($documentDiscount, $allocated, 6) : ($beforeDocument === '0' ? '0' : bcdiv(bcmul($documentDiscount, $base, 6), $beforeDocument, 6));
            $allocated = bcadd($allocated, $allocation, 6);
            $lineTaxable = bcsub($base, $allocation, 6);
            $rate = (string) ($line->tax_rate ?? 0);
            $lineTax = $line->tax_basis === 'inclusive' ? bcsub($lineTaxable, bcdiv($lineTaxable, bcadd('1', bcdiv($rate, '100', 6), 6), 6), 6) : ($rate === '0' ? '0' : bcdiv(bcmul($lineTaxable, $rate, 6), '100', 6));
            $lineTotal = $line->tax_basis === 'inclusive' ? $lineTaxable : bcadd($lineTaxable, $lineTax, 6);
            $line->taxable_amount = $lineTaxable;
            $line->tax_amount = $lineTax;
            $line->net_amount = $lineTotal;
            $line->save();
            $taxable = bcadd($taxable, $lineTaxable, 6);
            $tax = bcadd($tax, $lineTax, 6);
            $total = bcadd($total, $lineTotal, 6);
        }
        $sale->subtotal = $subtotal;
        $sale->line_discount_total = $lineDiscount;
        $sale->document_discount_total = $documentDiscount;
        $sale->taxable_amount = $taxable;
        $sale->tax_total = $tax;
        $sale->total = $total;
        $sale->paid_amount = '0';
        $sale->receivable_amount = $sale->payment_basis === 'credit' ? $total : '0';
        $sale->remaining_amount = $sale->receivable_amount;
        $sale->due_status = $this->dueStatus($sale->due_date, $company);
        $sale->save();
    }

    private function post(Sale $sale, Company $company, Request $request): string
    {
        if ($sale->status !== 'approved') {
            throw new RegistryConflictException('Only Approved Sales can be posted.', ['status' => $sale->status]);
        }
        if ($sale->payment_basis === 'cash') {
            $this->block($sale, 'collections', 'Cash Sales require Collections and a successful receipt before posting.');
        }
        if ($sale->lines->contains(fn ($line) => $line->stock_managed_snapshot)) {
            $this->block($sale, 'inventory', 'Stock-managed Sale lines require the Inventory movement workflow before posting.');
        }
        $accounts = $this->postingAccounts($company, (float) $sale->tax_total > 0);
        $business = BusinessTransaction::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'transaction_type' => 'credit_sale', 'status' => 'posted', 'actor_id' => $request->user()?->id, 'business_date' => $sale->sale_date, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_key' => 'sale:'.$sale->id]);
        $accountingId = (string) Str::uuid();
        DB::table('accounting_transactions')->insert(['id' => $accountingId, 'business_transaction_id' => $business->id, 'company_id' => $company->id, 'transaction_type' => 'credit_sale', 'status' => 'posted', 'business_date' => $sale->sale_date, 'created_by' => $request->user()?->id, 'correlation_id' => $request->attributes->get('correlation_id'), 'created_at' => now(), 'updated_at' => now()]);
        $currency = $sale->currency()->firstOrFail();
        $this->accountingLine($accountingId, $accounts['receivable']->id, $sale->total, '0', $currency->code, 'Receivable for '.$sale->sale_number);
        $revenue = bcsub((string) $sale->total, (string) $sale->tax_total, 6);
        $this->accountingLine($accountingId, $accounts['revenue']->id, '0', $revenue, $currency->code, 'Sales revenue for '.$sale->sale_number);
        if (bccomp((string) $sale->tax_total, '0', 6) > 0) {
            $this->accountingLine($accountingId, $accounts['tax']->id, '0', $sale->tax_total, $currency->code, 'Sales tax for '.$sale->sale_number);
        }
        $receivable = ReceivableOpenItem::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'customer_id' => $sale->customer_id, 'source_sale_id' => $sale->id, 'source_document_number' => $sale->sale_number, 'currency_id' => $sale->currency_id, 'original_amount' => $sale->total, 'remaining_amount' => $sale->total, 'due_date' => $sale->due_date, 'settlement_status' => 'unpaid', 'due_status' => $sale->due_status, 'dispute_status' => 'not_disputed', 'last_calculated_at' => now(), 'version' => 1]);
        $sale->status = 'posted';
        $sale->posted_by = $request->user()?->id;
        $sale->posted_at = now();
        $sale->business_transaction_id = $business->id;
        $sale->accounting_transaction_id = $accountingId;
        $sale->version++;
        $sale->blocked_code = null;
        $sale->blocked_reason = null;
        $sale->idempotency_identity = $request->header('Idempotency-Key') ?? $sale->idempotency_identity;
        $sale->save();

        return 'posted';
    }

    private function postingAccounts(Company $company, bool $needsTax): array
    {
        $active = fn (string $classification) => AccountTitle::where('company_id', $company->id)->where('classification', $classification)->where('status', 'active')->where('posting_eligible', true);
        $receivable = (clone $active('asset'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%receivable%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%receivable%'])->orWhereRaw('LOWER(code) LIKE ?', ['%receivable%']))->first();
        $revenue = (clone $active('income'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%revenue%'])->orWhereRaw('LOWER(name) LIKE ?', ['%sales%'])->orWhereRaw('LOWER(name) LIKE ?', ['%income%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%revenue%']))->first();
        $tax = $needsTax ? (clone $active('liability'))->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%tax%'])->orWhereRaw('LOWER(name) LIKE ?', ['%vat%'])->orWhereRaw('LOWER(account_subtype) LIKE ?', ['%tax%']))->first() : null;
        if (! $receivable || ! $revenue || ($needsTax && ! $tax)) {
            throw new RegistryConflictException('Sales posting is blocked until active posting Account Titles are configured for Receivables, Sales Revenue, and applicable Tax.', ['dependency' => 'account_titles', 'receivable' => ! (bool) $receivable, 'revenue' => ! (bool) $revenue, 'tax' => $needsTax && ! $tax]);
        }

        return compact('receivable', 'revenue', 'tax');
    }

    private function accountingLine(string $transactionId, string $accountId, string $debit, string $credit, string $currency, string $description): void
    {
        DB::table('accounting_transaction_lines')->insert(['id' => (string) Str::uuid(), 'accounting_transaction_id' => $transactionId, 'account_title_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'currency_code' => $currency, 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function review(Sale $sale, ?int $actor): ?string
    {
        if ($sale->status !== 'for_approval') {
            throw new RegistryConflictException('Only Sales waiting for approval can be reviewed.');
        } $sale->reviewed_by = $actor;
        $sale->reviewed_at = now();
        $sale->version++;
        $sale->save();

        return 'for_approval';
    }

    private function approve(Sale $sale, ?int $actor): string
    {
        if ($sale->status !== 'for_approval') {
            throw new RegistryConflictException('Only Sales waiting for approval can be approved.');
        } if ($actor && in_array($actor, array_filter([$sale->created_by, $sale->submitted_by]), true)) {
            throw new RegistryConflictException('The preparer cannot approve the same Sale.');
        } $sale->approved_by = $actor;
        $sale->approved_at = now();
        $sale->status = 'approved';
        $sale->version++;
        $sale->save();

        return 'approved';
    }

    private function cancel(Sale $sale, ?int $actor, ?string $reason): string
    {
        if (in_array($sale->status, ['posted', 'reversed', 'cancelled'], true)) {
            throw new RegistryConflictException('This Sale cannot be cancelled from its current status.');
        } if (! $reason) {
            throw new RegistryConflictException('A cancellation reason is required.');
        } $sale->status = 'cancelled';
        $sale->cancelled_by = $actor;
        $sale->cancelled_at = now();
        $sale->cancellation_reason = $reason;
        $sale->version++;
        $sale->save();

        return 'cancelled';
    }

    private function transitionTo(Sale $sale, string $from, string $to): string
    {
        if ($sale->status !== $from) {
            throw new RegistryConflictException('This Sale cannot be submitted from its current status.', ['status' => $sale->status]);
        } $sale->status = $to;
        $sale->submitted_by = request()->user()?->id;
        $sale->submitted_at = now();
        $sale->version++;
        $sale->save();

        return $to;
    }

    private function block(Sale $sale, string $code, string $reason): never
    {
        $sale->status = 'failed';
        $sale->blocked_code = $code;
        $sale->blocked_reason = $reason;
        $sale->version++;
        $sale->save();
        throw new RegistryConflictException($reason, ['dependency' => $code]);
    }

    private function assertVersion(Sale $sale, array $input): void
    {
        if (array_key_exists('version', $input) && (int) $input['version'] !== (int) $sale->version) {
            throw new RegistryConflictException('This Sale was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
    }

    private function currency(?string $id, Company $company): ReferenceCurrency
    {
        $currency = ReferenceCurrency::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->first();
        if (! $currency) {
            throw new RegistryConflictException('An active same-company Currency is required.', ['dependency' => 'currency']);
        }

        return $currency;
    }

    private function paymentTerm(?string $id, Company $company): PaymentTerm
    {
        $term = PaymentTerm::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->first();
        if (! $term) {
            throw new RegistryConflictException('An active same-company Payment Term is required for credit sales.', ['dependency' => 'payment_term']);
        }

        return $term;
    }

    private function customer(?string $id, Company $company, bool $required): ?BusinessPartner
    {
        if (! $id && ! $required) {
            return null;
        } $customer = BusinessPartner::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('role', 'customer')->where('status', 'active'))->first();
        if (! $customer) {
            throw new RegistryConflictException('An active same-company customer is required for a credit Sale.', ['dependency' => 'customer']);
        }

        return $customer;
    }

    private function dueDate($date, ?PaymentTerm $term, ?string $provided, Company $company): ?string
    {
        if ($term?->term_type === 'due_date') {
            if (! $provided || Carbon::parse($provided)->lt(Carbon::parse($date))) {
                throw new RegistryConflictException('A due date on or after the Sale date is required.');
            }

            return $provided;
        } $due = Carbon::parse($date, $company->timezone ?: config('app.timezone'));
        if (($term?->due_days ?? 0) > 0) {
            $due->addDays($term->due_days);
        } if ($term?->end_of_month) {
            $due->endOfMonth();
        }

        return $due->toDateString();
    }

    private function dueStatus($dueDate, Company $company): string
    {
        if (! $dueDate) {
            return 'no_due_date';
        } $due = Carbon::parse($dueDate);
        $today = Carbon::today($company->timezone ?: config('app.timezone'));

        return $due->lt($today) ? 'overdue' : ($due->equalTo($today) ? 'due_today' : 'not_yet_due');
    }

    private function audit(Request $request, string $action, Sale|BillingStatement $record, array $before, array $after, string $description): void
    {
        $this->audit->record($request, $action, $record, $record->company_id, $before, $after, null, 'Sales & Receivables', $description);
    }

    private function returnForCorrection(Sale $sale, ?int $actor, ?string $reason): string
    {
        if ($sale->status !== 'for_approval') {
            throw new RegistryConflictException('Only Sales waiting for approval can be returned for correction.');
        }
        if (! $reason) {
            throw new RegistryConflictException('A return-for-correction reason is required.');
        }
        $sale->status = 'draft';
        $sale->submitted_by = null;
        $sale->submitted_at = null;
        $sale->reviewed_by = $actor;
        $sale->reviewed_at = now();
        $sale->version++;
        $sale->save();

        return 'draft';
    }
}
