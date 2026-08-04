<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\ReasonCode;
use App\Models\ReferenceCurrency;
use App\Models\RegistryHistory;
use App\Models\StockLocation;
use App\Models\TaxCode;
use App\Models\Warehouse;
use App\Support\AuditService;
use App\Support\CurrencyCatalog;
use App\Support\RegistryNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ReferenceRegistryService
{
    public function __construct(private readonly AuditService $audit) {}

    public function createCurrency(array $input, Company $company, Request $request): ReferenceCurrency
    {
        $code = strtoupper($input['code']);
        $catalog = CurrencyCatalog::get($code);
        if (! $catalog) {
            throw new RegistryConflictException('The currency code is not recognized by the supported ISO reference catalog.');
        }
        if (ReferenceCurrency::where('company_id', $company->id)->where('normalized_code', strtolower($code))->exists()) {
            throw new RegistryConflictException('A currency with this code already exists in the company.');
        }

        return $this->createRecord(ReferenceCurrency::class, 'currency', $company, $request, [...$input, 'code' => $code, 'name' => $input['name'] ?? $catalog[0], 'symbol' => $input['symbol'] ?? $catalog[1], 'decimal_precision' => $input['decimal_precision'] ?? $catalog[2]], ['code', 'name', 'symbol', 'decimal_precision', 'effective_from', 'effective_to', 'system_standard', 'locked']);
    }

    public function createPaymentMethod(array $input, Company $company, Request $request): PaymentMethod
    {
        if (empty($input['supports_incoming']) && empty($input['supports_outgoing'])) {
            throw new RegistryConflictException('A payment method must support incoming or outgoing use.');
        }

        return $this->createRecord(PaymentMethod::class, 'payment_method', $company, $request, $input, ['code', 'name', 'method_class', 'description', 'requires_external_reference', 'requires_account_selection', 'supports_incoming', 'supports_outgoing', 'clearing_behavior', 'effective_from', 'effective_to']);
    }

    public function createPaymentTerm(array $input, Company $company, Request $request): PaymentTerm
    {
        $type = $input['term_type'] ?? 'immediate';
        $days = (int) ($input['due_days'] ?? 0);
        if ($days < 0 || ($type === 'immediate' && $days !== 0)) {
            throw new RegistryConflictException('Immediate payment terms must have zero due days, and due days cannot be negative.');
        }

        return $this->createRecord(PaymentTerm::class, 'payment_term', $company, $request, [...$input, 'term_type' => $type, 'due_days' => $days], ['code', 'name', 'description', 'term_type', 'due_days', 'end_of_month', 'effective_from', 'effective_to']);
    }

    public function createTaxCode(array $input, Company $company, Request $request): TaxCode
    {
        $rate = (float) ($input['rate'] ?? 0);
        if ($rate < 0 || $rate > 100) {
            throw new RegistryConflictException('Tax rates must be between 0 and 100 percent.');
        }

        return $this->createRecord(TaxCode::class, 'tax_code', $company, $request, [...$input, 'rate' => $rate], ['code', 'name', 'description', 'tax_type', 'rate', 'basis', 'recoverable', 'withholding', 'jurisdiction', 'effective_from', 'effective_to']);
    }

    public function createAccountTitle(array $input, Company $company, Request $request): AccountTitle
    {
        if (empty($input['classification']) || empty($input['normal_balance'])) {
            throw new RegistryConflictException('Account classification and normal balance are required.');
        }

        return $this->createRecord(AccountTitle::class, 'account_title', $company, $request, $input, ['code', 'name', 'description', 'classification', 'normal_balance', 'account_subtype', 'posting_eligible', 'system_standard', 'locked', 'effective_from', 'effective_to']);
    }

    public function createExpenseCategory(array $input, Company $company, Request $request): ExpenseCategory
    {
        $account = AccountTitle::where('company_id', $company->id)->whereKey($input['account_title_id'] ?? '')->where('classification', 'expense')->where('status', 'active')->first();
        if (! $account) {
            throw new RegistryConflictException('An active Expense-classified Account Title is required.');
        }

        return $this->createRecord(ExpenseCategory::class, 'expense_category', $company, $request, $input, ['code', 'name', 'description', 'account_title_id', 'reporting_tag', 'effective_from', 'effective_to']);
    }

    public function createBranch(array $input, Company $company, Request $request): Branch
    {
        return $this->createRecord(Branch::class, 'branch', $company, $request, $input, ['code', 'name', 'description', 'branch_type', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'contact_name', 'contact_email', 'contact_phone', 'effective_from', 'effective_to']);
    }

    public function createWarehouse(array $input, Company $company, Request $request): Warehouse
    {
        if (! empty($input['branch_id']) && ! Branch::where('company_id', $company->id)->whereKey($input['branch_id'])->where('status', 'active')->exists()) {
            throw new RegistryConflictException('The selected Branch is not active in this company.');
        }

        return $this->createRecord(Warehouse::class, 'warehouse', $company, $request, $input, ['branch_id', 'code', 'name', 'description', 'warehouse_type', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'effective_from', 'effective_to']);
    }

    public function createStockLocation(array $input, Company $company, Request $request): StockLocation
    {
        $warehouse = Warehouse::where('company_id', $company->id)->whereKey($input['warehouse_id'] ?? '')->where('status', 'active')->first();
        if (! $warehouse) {
            throw new RegistryConflictException('An active same-company Warehouse is required.');
        }
        $this->validateLocationParent($input['parent_id'] ?? null, $warehouse, $company);

        return $this->createRecord(StockLocation::class, 'stock_location', $company, $request, $input, ['warehouse_id', 'parent_id', 'code', 'name', 'location_type', 'description', 'sellable', 'effective_from', 'effective_to']);
    }

    public function createReasonCode(array $input, Company $company, Request $request): ReasonCode
    {
        if (empty($input['domain'])) {
            throw new RegistryConflictException('A Reason Code domain is required.');
        }

        return $this->createRecord(ReasonCode::class, 'reason_code', $company, $request, $input, ['code', 'name', 'description', 'domain', 'requires_explanation', 'requires_evidence', 'effective_from', 'effective_to']);
    }

    public function update(Model $record, string $type, array $input, Company $company, Request $request): Model
    {
        if ((int) ($input['version'] ?? 0) !== (int) $record->version) {
            throw new RegistryConflictException('This reference was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        if ($record instanceof AccountTitle && $record->locked) {
            throw new RegistryConflictException('Locked Account Titles cannot be materially edited.');
        }
        if ($record instanceof StockLocation && array_key_exists('parent_id', $input)) {
            $this->validateLocationParent($input['parent_id'], Warehouse::where('company_id', $company->id)->whereKey($record->warehouse_id)->where('status', 'active')->firstOrFail(), $company, $record->id);
        }
        if ($record instanceof Warehouse && array_key_exists('branch_id', $input) && $input['branch_id'] && ! Branch::where('company_id', $company->id)->whereKey($input['branch_id'])->where('status', 'active')->exists()) {
            throw new RegistryConflictException('The selected Branch is not active in this company.');
        }
        if ($record instanceof ExpenseCategory && array_key_exists('account_title_id', $input) && ! AccountTitle::where('company_id', $company->id)->whereKey($input['account_title_id'])->where('classification', 'expense')->where('status', 'active')->exists()) {
            throw new RegistryConflictException('Expense Categories must map to an active Expense-classified Account Title.');
        }
        if ($record instanceof PaymentMethod && array_key_exists('supports_incoming', $input) && ! ($input['supports_incoming'] || ($input['supports_outgoing'] ?? $record->supports_outgoing))) {
            throw new RegistryConflictException('A payment method must support incoming or outgoing use.');
        }
        if ($record instanceof PaymentMethod && array_key_exists('supports_outgoing', $input) && ! ($input['supports_outgoing'] || ($input['supports_incoming'] ?? $record->supports_incoming))) {
            throw new RegistryConflictException('A payment method must support incoming or outgoing use.');
        }
        if ($record instanceof PaymentTerm && (($input['term_type'] ?? $record->term_type) === 'immediate') && (int) ($input['due_days'] ?? $record->due_days) !== 0) {
            throw new RegistryConflictException('Immediate payment terms must have zero due days.');
        }
        $allowed = $this->allowedFields($record);
        $updates = array_intersect_key($input, array_flip($allowed));
        if (isset($updates['code'])) {
            $updates['normalized_code'] = RegistryNormalizer::code($updates['code']);
        }
        if ($record instanceof ReferenceCurrency && isset($updates['code'])) {
            $updates['code'] = strtoupper($updates['code']);
            if (! CurrencyCatalog::get($updates['code'])) {
                throw new RegistryConflictException('The currency code is not recognized by the supported ISO reference catalog.');
            }
        }
        $this->ensureUnique($record, $company, $updates['normalized_code'] ?? $record->normalized_code, $record instanceof ReasonCode ? $record->domain : null);
        $updates['updated_by'] = $request->user()?->id;
        $updates['version'] = $record->version + 1;
        if ($record::where('company_id', $company->id)->whereKey($record->id)->where('version', $record->version)->update($updates) !== 1) {
            throw new RegistryConflictException('This reference was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $record->refresh();
        $this->record($request, $type.'.updated', $record, $company, [], $record->toArray(), null, ucfirst(str_replace('_', ' ', $type)).' updated', 'A shared transaction reference was updated.');

        return $record;
    }

    public function transition(Model $record, string $type, string $status, string $reason, Company $company, Request $request): Model
    {
        if ($reason === '') {
            throw new RegistryConflictException('A reason is required for a status change.');
        }
        $defaults = ['currency' => 'default_currency_id', 'payment_method' => 'default_payment_method_id', 'payment_term' => 'default_payment_term_id', 'branch' => 'default_branch_id', 'warehouse' => 'default_warehouse_id', 'stock_location' => 'default_stock_location_id', 'expense_category' => 'default_expense_category_id', 'account_title' => 'default_expense_account_title_id'];
        if ($status === 'inactive' && isset($defaults[$type]) && $company->{$defaults[$type]} === $record->id) {
            throw new RegistryConflictException('This reference is a company default and must be replaced before deactivation.', ['dependency' => 'company_default']);
        }
        if ($status === 'inactive' && $record instanceof AccountTitle && ExpenseCategory::where('company_id', $company->id)->where('account_title_id', $record->id)->where('status', 'active')->exists()) {
            throw new RegistryConflictException('This Account Title is used by active Expense Categories.', ['dependency' => 'expense_categories']);
        }
        if ($status === 'inactive' && $record instanceof AccountTitle && Company::where('opening_balance_offset_account_title_id', $record->id)->exists()) {
            throw new RegistryConflictException('This Account Title is configured as an Opening Balance offset.', ['dependency' => 'opening_balance_offset_account_title']);
        }
        if ($status === 'inactive' && $record instanceof Branch && Warehouse::where('company_id', $company->id)->where('branch_id', $record->id)->where('status', 'active')->exists()) {
            throw new RegistryConflictException('This Branch is used by active Warehouses.', ['dependency' => 'warehouses']);
        }
        if ($status === 'inactive' && $record instanceof Warehouse && StockLocation::where('company_id', $company->id)->where('warehouse_id', $record->id)->where('status', 'active')->exists()) {
            throw new RegistryConflictException('This Warehouse is used by active Stock Locations.', ['dependency' => 'stock_locations']);
        }
        if ($status === 'inactive' && $record instanceof StockLocation && StockLocation::where('company_id', $company->id)->where('parent_id', $record->id)->where('status', 'active')->exists()) {
            throw new RegistryConflictException('This Stock Location has active child locations.', ['dependency' => 'stock_locations']);
        }
        $before = $record->toArray();
        $updated = $record::where('company_id', $company->id)->whereKey($record->id)->where('version', $record->version)->update(['status' => $status, 'version' => $record->version + 1, 'updated_by' => $request->user()?->id, 'status_changed_at' => now(), 'status_changed_by' => $request->user()?->id, 'status_reason' => $reason]);
        if ($updated !== 1) {
            throw new RegistryConflictException('This reference was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $record->refresh();
        $action = $status === 'active' ? 'reactivated' : 'deactivated';
        $this->record($request, $type.'.'.$action, $record, $company, $before, $record->toArray(), $reason, ucfirst(str_replace('_', ' ', $type)).' '.$action, 'A shared transaction reference changed lifecycle status.');

        return $record;
    }

    public function updateCompanyDefaults(array $input, Company $company, Request $request): Company
    {
        $map = ['default_currency_id' => ReferenceCurrency::class, 'default_branch_id' => Branch::class, 'default_payment_term_id' => PaymentTerm::class, 'default_payment_method_id' => PaymentMethod::class, 'default_warehouse_id' => Warehouse::class, 'default_stock_location_id' => StockLocation::class, 'default_expense_category_id' => ExpenseCategory::class, 'default_expense_account_title_id' => AccountTitle::class, 'opening_balance_offset_account_title_id' => AccountTitle::class];
        $updates = [];
        foreach ($map as $field => $class) {
            if (! array_key_exists($field, $input)) {
                continue;
            } $id = $input[$field];
            if ($id !== null && ! $class::where('company_id', $company->id)->whereKey($id)->where('status', 'active')->exists()) {
                throw new RegistryConflictException('A default reference must be active and belong to the current company.', ['field' => $field]);
            } $updates[$field] = $id;
        }
        $before = $company->only(array_keys($updates));
        $company->forceFill($updates)->save();
        $this->audit->record($request, 'company.defaults.updated', $company, $company->id, $before, $company->fresh()->only(array_keys($updates)), null, 'Company defaults updated', 'Company default reference selections were updated.');

        return $company->fresh();
    }

    private function createRecord(string $class, string $type, Company $company, Request $request, array $input, array $fields): Model
    {
        $code = RegistryNormalizer::code($input['code']);
        $query = $class::where('company_id', $company->id)->where('normalized_code', $code);
        if ($class === ReasonCode::class) {
            $query->where('domain', RegistryNormalizer::text($input['domain'] ?? ''));
        }
        if ($query->exists()) {
            throw new RegistryConflictException('A reference with this code already exists in the documented company scope.');
        }
        $values = ['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => $class === ReferenceCurrency::class ? strtoupper($input['code']) : trim($input['code']), 'normalized_code' => $code, 'status' => 'active', 'effective_from' => $input['effective_from'] ?? now()->toDateString(), 'effective_to' => $input['effective_to'] ?? null, 'version' => 1, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id, 'source_channel' => $request->input('source_channel', 'api'), 'correlation_id' => $request->attributes->get('correlation_id')];
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $input[$field];
            }
        }
        $record = $class::create($values);
        $this->record($request, $type.'.created', $record, $company, [], $record->toArray(), null, ucfirst(str_replace('_', ' ', $type)).' created', 'A shared transaction reference was created.');

        return $record;
    }

    private function allowedFields(Model $record): array
    {
        return match (true) {
            $record instanceof ReferenceCurrency => ['code', 'name', 'symbol', 'decimal_precision', 'effective_from', 'effective_to'], $record instanceof PaymentMethod => ['code', 'name', 'method_class', 'description', 'requires_external_reference', 'requires_account_selection', 'supports_incoming', 'supports_outgoing', 'clearing_behavior', 'effective_from', 'effective_to'], $record instanceof PaymentTerm => ['code', 'name', 'description', 'term_type', 'due_days', 'end_of_month', 'effective_from', 'effective_to'], $record instanceof TaxCode => ['code', 'name', 'description', 'tax_type', 'rate', 'basis', 'recoverable', 'withholding', 'jurisdiction', 'effective_from', 'effective_to'], $record instanceof AccountTitle => ['code', 'name', 'description', 'classification', 'normal_balance', 'account_subtype', 'posting_eligible', 'effective_from', 'effective_to'], $record instanceof ExpenseCategory => ['code', 'name', 'description', 'account_title_id', 'reporting_tag', 'effective_from', 'effective_to'], $record instanceof Branch => ['code', 'name', 'description', 'branch_type', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'contact_name', 'contact_email', 'contact_phone', 'effective_from', 'effective_to'], $record instanceof Warehouse => ['branch_id', 'code', 'name', 'description', 'warehouse_type', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'effective_from', 'effective_to'], $record instanceof StockLocation => ['warehouse_id', 'parent_id', 'code', 'name', 'location_type', 'description', 'sellable', 'effective_from', 'effective_to'], default => ['code', 'name', 'description', 'domain', 'requires_explanation', 'requires_evidence', 'effective_from', 'effective_to']
        };
    }

    private function ensureUnique(Model $record, Company $company, string $normalizedCode, ?string $domain = null): void
    {
        $query = $record::where('company_id', $company->id)->where('normalized_code', $normalizedCode)->where('id', '!=', $record->id);
        if ($record instanceof ReasonCode) {
            $query->where('domain', $domain ?? $record->domain);
        } if ($query->exists()) {
            throw new RegistryConflictException('A reference with this code already exists in the documented company scope.');
        }
    }

    private function validateLocationParent(?string $parentId, Warehouse $warehouse, Company $company, ?string $self = null): void
    {
        if (! $parentId) {
            return;
        } if ($parentId === $self) {
            throw new RegistryConflictException('A Stock Location cannot be its own parent.');
        } $parent = StockLocation::where('company_id', $company->id)->where('warehouse_id', $warehouse->id)->whereKey($parentId)->first();
        if (! $parent) {
            throw new RegistryConflictException('The parent Stock Location must belong to the same Warehouse.');
        } $visited = [];
        while ($parent) {
            if (in_array($parent->id, $visited, true)) {
                throw new RegistryConflictException('Circular Stock Location hierarchy detected.');
            } $visited[] = $parent->id;
            if ($parent->id === $self) {
                throw new RegistryConflictException('A Stock Location cannot become its own descendant.');
            } $parent = $parent->parent_id ? StockLocation::find($parent->parent_id) : null;
        }
    }

    private function record(Request $request, string $action, Model $record, Company $company, array $before, array $after, ?string $reason, ?string $title, ?string $description): void
    {
        $this->audit->record($request, $action, $record, $company->id, $before, $after, $reason, $title, $description);
        RegistryHistory::create(['company_id' => $company->id, 'registry_type' => $this->typeFor($record), 'record_id' => $record->getKey(), 'action' => $action, 'before_state' => $before ?: null, 'after_state' => $after ?: null, 'reason' => $reason, 'actor_id' => $request->user()?->id, 'source_channel' => $request->input('source_channel', 'api'), 'correlation_id' => $request->attributes->get('correlation_id')]);
    }

    private function typeFor(Model $record): string
    {
        return match (true) {
            $record instanceof ReferenceCurrency => 'currency', $record instanceof PaymentMethod => 'payment_method', $record instanceof PaymentTerm => 'payment_term', $record instanceof TaxCode => 'tax_code', $record instanceof AccountTitle => 'account_title', $record instanceof ExpenseCategory => 'expense_category', $record instanceof Branch => 'branch', $record instanceof Warehouse => 'warehouse', $record instanceof StockLocation => 'stock_location', default => 'reason_code'
        };
    }
}
