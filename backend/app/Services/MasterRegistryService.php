<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\BusinessPartner;
use App\Models\BusinessPartnerAddress;
use App\Models\BusinessPartnerContact;
use App\Models\BusinessPartnerRole;
use App\Models\Company;
use App\Models\ProductService;
use App\Models\RegistryCategory;
use App\Models\RegistryExternalIdentifier;
use App\Models\RegistryHistory;
use App\Models\UnitOfMeasure;
use App\Support\AuditService;
use App\Support\RegistryNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MasterRegistryService
{
    public function __construct(private readonly AuditService $audit) {}

    public function createBusinessPartner(array $input, Company $company, Request $request): BusinessPartner
    {
        $code = RegistryNormalizer::code($input['code']);
        if (BusinessPartner::where('company_id', $company->id)->where('normalized_code', $code)->exists()) {
            throw new RegistryConflictException('A business partner with this code already exists in the company.');
        }
        $potential = $this->partnerDuplicates($company, $input);
        if ($potential && empty($input['duplicate_override'])) {
            throw new RegistryConflictException('Potential duplicate business partners were found. Review them before saving.', ['duplicates' => $potential]);
        }

        return DB::transaction(function () use ($input, $company, $request, $code, $potential) {
            $record = BusinessPartner::create([
                'id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => trim($input['code']), 'normalized_code' => $code,
                'party_type' => $input['party_type'] ?? 'organization', 'official_name' => trim($input['official_name']),
                'display_name' => trim($input['display_name'] ?? $input['official_name']), 'trade_name' => $input['trade_name'] ?? null,
                'tax_reference' => $input['tax_reference'] ?? null, 'primary_email' => isset($input['primary_email']) ? strtolower(trim($input['primary_email'])) : null,
                'primary_phone' => $input['primary_phone'] ?? null, 'notes' => $input['notes'] ?? null, 'status' => 'active',
                'effective_from' => $input['effective_from'] ?? now()->toDateString(), 'effective_to' => $input['effective_to'] ?? null,
                'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id, 'source_channel' => $input['source_channel'] ?? 'api',
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]);
            $this->syncPartnerRoles($record, $company, $input['roles'] ?? [], $request);
            $this->syncExternalIdentifiers($record, 'business_partner', $company, $input['external_identifiers'] ?? []);
            $this->record($request, 'business_partner.created', $record, $company, [], $record->toArray(), null, 'Business partner created', 'A business partner was added to the master registry.');
            if ($potential) {
                $this->record($request, 'business_partner.duplicate_override', $record, $company, [], ['duplicates' => $potential], $input['duplicate_override_reason'] ?? 'Duplicate warning acknowledged.', 'Duplicate warning overridden', 'A potential duplicate warning was explicitly overridden.');
            }

            return $record->load(['roles', 'contacts', 'addresses']);
        });
    }

    public function updateBusinessPartner(BusinessPartner $record, array $input, Company $company, Request $request): BusinessPartner
    {
        if ((int) ($input['version'] ?? 0) !== (int) $record->version) {
            throw new RegistryConflictException('This business partner was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $before = $record->toArray();
        $updates = array_intersect_key($input, array_flip(['code', 'party_type', 'official_name', 'display_name', 'trade_name', 'tax_reference', 'primary_email', 'primary_phone', 'notes', 'effective_from', 'effective_to']));
        if (isset($updates['code'])) {
            $updates['normalized_code'] = RegistryNormalizer::code($updates['code']);
            if (BusinessPartner::where('company_id', $company->id)->where('normalized_code', $updates['normalized_code'])->where('id', '!=', $record->id)->exists()) {
                throw new RegistryConflictException('A business partner with this code already exists in the company.');
            }
        }
        if (isset($updates['primary_email'])) {
            $updates['primary_email'] = strtolower(trim($updates['primary_email']));
        }
        $updates['updated_by'] = $request->user()?->id;
        $updates['version'] = $record->version + 1;
        $updated = BusinessPartner::where('company_id', $company->id)->whereKey($record->id)->where('version', $record->version)->update($updates);
        if ($updated !== 1) {
            throw new RegistryConflictException('This business partner was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $record->refresh();
        if (array_key_exists('roles', $input)) {
            $this->syncPartnerRoles($record, $company, $input['roles'], $request);
        }
        if (array_key_exists('external_identifiers', $input)) {
            $this->syncExternalIdentifiers($record, 'business_partner', $company, $input['external_identifiers']);
        }
        $this->record($request, 'business_partner.updated', $record, $company, $before, $record->toArray(), null, 'Business partner updated', 'Master registry identity details were updated.');

        return $record->load(['roles', 'contacts', 'addresses']);
    }

    public function createProduct(array $input, Company $company, Request $request): ProductService
    {
        $code = RegistryNormalizer::code($input['code']);
        if (ProductService::where('company_id', $company->id)->where('normalized_code', $code)->exists()) {
            throw new RegistryConflictException('A product or service with this code already exists in the company.');
        }
        $this->validateProductReferences($input, $company);
        $potential = ProductService::where('company_id', $company->id)->where(function ($query) use ($input) {
            $query->whereRaw('lower(name) = ?', [RegistryNormalizer::text($input['name'])]);
            if (! empty($input['barcode'])) {
                $query->orWhere('barcode', $input['barcode']);
            }
        })->limit(5)->get()->map(fn ($item) => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name])->all();
        if ($potential && empty($input['duplicate_override'])) {
            throw new RegistryConflictException('Potential duplicate products or services were found. Review them before saving.', ['duplicates' => $potential]);
        }

        return DB::transaction(function () use ($input, $company, $request, $code, $potential) {
            $stock = (bool) ($input['stock_managed'] ?? false);
            $record = ProductService::create([
                'id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => trim($input['code']), 'normalized_code' => $code,
                'name' => trim($input['name']), 'description' => $input['description'] ?? null, 'record_type' => $input['record_type'],
                'category_id' => $input['category_id'] ?? null, 'base_unit_id' => $input['base_unit_id'] ?? null,
                'sellable' => $input['sellable'] ?? true, 'purchasable' => $input['purchasable'] ?? false, 'stock_managed' => $stock,
                'non_stock' => $input['non_stock'] ?? ! $stock, 'standard_selling_price' => $input['standard_selling_price'] ?? null,
                'standard_purchase_price' => $input['standard_purchase_price'] ?? null, 'tax_reference' => $input['tax_reference'] ?? null,
                'barcode' => $input['barcode'] ?? null, 'status' => 'active', 'effective_from' => $input['effective_from'] ?? now()->toDateString(),
                'effective_to' => $input['effective_to'] ?? null, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id,
                'source_channel' => $input['source_channel'] ?? 'api', 'correlation_id' => $request->attributes->get('correlation_id'),
            ]);
            $this->syncExternalIdentifiers($record, 'product_service', $company, $input['external_identifiers'] ?? []);
            $this->record($request, 'product_service.created', $record, $company, [], $record->toArray(), null, 'Product or service created', 'A product or service was added to the master registry.');
            if ($potential) {
                $this->record($request, 'product_service.duplicate_override', $record, $company, [], ['duplicates' => $potential], $input['duplicate_override_reason'] ?? 'Duplicate warning acknowledged.', 'Duplicate warning overridden', 'A potential duplicate warning was explicitly overridden.');
            }

            return $record->load(['category', 'baseUnit']);
        });
    }

    public function updateProduct(ProductService $record, array $input, Company $company, Request $request): ProductService
    {
        if ((int) ($input['version'] ?? 0) !== (int) $record->version) {
            throw new RegistryConflictException('This product or service was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $before = $record->toArray();
        $this->validateProductReferences($input + ['record_type' => $input['record_type'] ?? $record->record_type, 'stock_managed' => $input['stock_managed'] ?? $record->stock_managed], $company);
        $updates = array_intersect_key($input, array_flip(['code', 'name', 'description', 'record_type', 'category_id', 'base_unit_id', 'sellable', 'purchasable', 'stock_managed', 'non_stock', 'standard_selling_price', 'standard_purchase_price', 'tax_reference', 'barcode', 'effective_from', 'effective_to']));
        if (isset($updates['code'])) {
            $updates['normalized_code'] = RegistryNormalizer::code($updates['code']);
            if (ProductService::where('company_id', $company->id)->where('normalized_code', $updates['normalized_code'])->where('id', '!=', $record->id)->exists()) {
                throw new RegistryConflictException('A product or service with this code already exists in the company.');
            }
        }
        $updates['updated_by'] = $request->user()?->id;
        $updates['version'] = $record->version + 1;
        if (isset($updates['stock_managed'])) {
            $updates['non_stock'] = ! (bool) $updates['stock_managed'];
        }
        if (ProductService::where('company_id', $company->id)->whereKey($record->id)->where('version', $record->version)->update($updates) !== 1) {
            throw new RegistryConflictException('This product or service was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $record->refresh();
        if (array_key_exists('external_identifiers', $input)) {
            $this->syncExternalIdentifiers($record, 'product_service', $company, $input['external_identifiers']);
        }
        $this->record($request, 'product_service.updated', $record, $company, $before, $record->toArray(), null, 'Product or service updated', 'Master registry item details were updated.');

        return $record->load(['category', 'baseUnit']);
    }

    public function createCategory(array $input, Company $company, Request $request): RegistryCategory
    {
        $code = RegistryNormalizer::code($input['code']);
        if (RegistryCategory::where('company_id', $company->id)->where('normalized_code', $code)->exists()) {
            throw new RegistryConflictException('A category with this code already exists in the company.');
        }
        $this->validateParent($input['parent_id'] ?? null, $company);
        $record = RegistryCategory::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => trim($input['code']), 'normalized_code' => $code, 'name' => trim($input['name']), 'description' => $input['description'] ?? null, 'parent_id' => $input['parent_id'] ?? null, 'applicability' => $input['applicability'] ?? 'both', 'display_order' => $input['display_order'] ?? 0, 'status' => 'active', 'effective_from' => $input['effective_from'] ?? now()->toDateString(), 'effective_to' => $input['effective_to'] ?? null, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id]);
        $this->record($request, 'category.created', $record, $company, [], $record->toArray(), null, 'Category created', 'A product or service category was added.');

        return $record;
    }

    public function updateCategory(RegistryCategory $record, array $input, Company $company, Request $request): RegistryCategory
    {
        if ((int) ($input['version'] ?? 0) !== (int) $record->version) {
            throw new RegistryConflictException('This category was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $before = $record->toArray();
        $this->validateParent($input['parent_id'] ?? $record->parent_id, $company, $record->id);
        $updates = array_intersect_key($input, array_flip(['code', 'name', 'description', 'parent_id', 'applicability', 'display_order', 'effective_from', 'effective_to']));
        if (isset($updates['code'])) {
            $updates['normalized_code'] = RegistryNormalizer::code($updates['code']);
            if (RegistryCategory::where('company_id', $company->id)->where('normalized_code', $updates['normalized_code'])->where('id', '!=', $record->id)->exists()) {
                throw new RegistryConflictException('A category with this code already exists in the company.');
            }
        }
        $updates['updated_by'] = $request->user()?->id;
        $updates['version'] = $record->version + 1;
        if (RegistryCategory::where('company_id', $company->id)->whereKey($record->id)->where('version', $record->version)->update($updates) !== 1) {
            throw new RegistryConflictException('This category was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $record->refresh();
        $this->record($request, 'category.updated', $record, $company, $before, $record->toArray(), null, 'Category updated', 'A product or service category was updated.');

        return $record;
    }

    public function createUnit(array $input, Company $company, Request $request): UnitOfMeasure
    {
        $code = RegistryNormalizer::code($input['code']);
        if (UnitOfMeasure::where('company_id', $company->id)->where('normalized_code', $code)->exists()) {
            throw new RegistryConflictException('A unit with this code already exists in the company.');
        }
        $precision = (int) ($input['decimal_precision'] ?? 0);
        if ($precision > 12) {
            throw new RegistryConflictException('Unit precision must be 12 or less.');
        }
        $record = UnitOfMeasure::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => trim($input['code']), 'normalized_code' => $code, 'name' => trim($input['name']), 'symbol' => $input['symbol'] ?? null, 'unit_type' => $input['unit_type'] ?? 'quantity', 'decimal_precision' => $precision, 'allows_fractional' => $input['allows_fractional'] ?? $precision > 0, 'status' => 'active', 'effective_from' => $input['effective_from'] ?? now()->toDateString(), 'effective_to' => $input['effective_to'] ?? null, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id]);
        $this->record($request, 'unit.created', $record, $company, [], $record->toArray(), null, 'Unit created', 'A unit of measure was added.');

        return $record;
    }

    public function updateUnit(UnitOfMeasure $record, array $input, Company $company, Request $request): UnitOfMeasure
    {
        if ((int) ($input['version'] ?? 0) !== (int) $record->version) {
            throw new RegistryConflictException('This unit was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $before = $record->toArray();
        $updates = array_intersect_key($input, array_flip(['code', 'name', 'symbol', 'unit_type', 'decimal_precision', 'allows_fractional', 'effective_from', 'effective_to']));
        if (isset($updates['decimal_precision']) && (int) $updates['decimal_precision'] > 12) {
            throw new RegistryConflictException('Unit precision must be 12 or less.');
        }
        if (isset($updates['code'])) {
            $updates['normalized_code'] = RegistryNormalizer::code($updates['code']);
            if (UnitOfMeasure::where('company_id', $company->id)->where('normalized_code', $updates['normalized_code'])->where('id', '!=', $record->id)->exists()) {
                throw new RegistryConflictException('A unit with this code already exists in the company.');
            }
        }
        $updates['updated_by'] = $request->user()?->id;
        $updates['version'] = $record->version + 1;
        if (UnitOfMeasure::where('company_id', $company->id)->whereKey($record->id)->where('version', $record->version)->update($updates) !== 1) {
            throw new RegistryConflictException('This unit was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $record->refresh();
        $this->record($request, 'unit.updated', $record, $company, $before, $record->toArray(), null, 'Unit updated', 'A unit of measure was updated.');

        return $record;
    }

    public function transition(Model $record, string $registryType, string $status, string $reason, Company $company, Request $request): Model
    {
        if ($reason === '') {
            throw new RegistryConflictException('A reason is required for a status change.');
        }
        if ($status === 'inactive' && $record instanceof RegistryCategory && ProductService::where('company_id', $company->id)->where('category_id', $record->id)->where('status', 'active')->exists()) {
            throw new RegistryConflictException('This category cannot be deactivated while active products or services use it.', ['dependency' => 'products_services']);
        }
        if ($status === 'inactive' && $record instanceof UnitOfMeasure && ProductService::where('company_id', $company->id)->where('base_unit_id', $record->id)->where('status', 'active')->exists()) {
            throw new RegistryConflictException('This unit cannot be deactivated while active products use it.', ['dependency' => 'products_services']);
        }
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw new RegistryConflictException('Only active and inactive lifecycle states are supported in Phase 2A.');
        }
        $before = $record->toArray();
        $updated = $record::where('company_id', $company->id)->whereKey($record->id)->where('version', $record->version)->update(['status' => $status, 'version' => $record->version + 1, 'updated_by' => $request->user()?->id, 'status_changed_at' => now(), 'status_changed_by' => $request->user()?->id, 'status_reason' => $reason]);
        if ($updated !== 1) {
            throw new RegistryConflictException('This registry record was changed by another user. Refresh and try again.', ['version_conflict' => true]);
        }
        $record->refresh();
        $action = $status === 'active' ? 'reactivated' : 'deactivated';
        $this->record($request, $registryType.'.'.$action, $record, $company, $before, $record->toArray(), $reason, ucfirst($registryType).' '.$action, 'A master registry record changed lifecycle status.');

        return $record;
    }

    public function createContact(BusinessPartner $partner, array $input, Company $company, Request $request): BusinessPartnerContact
    {
        if (($input['is_primary'] ?? false)) {
            BusinessPartnerContact::where('company_id', $company->id)->where('business_partner_id', $partner->id)->where('contact_type', $input['contact_type'] ?? 'general')->where('is_primary', true)->update(['is_primary' => false, 'updated_at' => now()]);
        }
        $contact = BusinessPartnerContact::create(['id' => (string) Str::uuid(), 'business_partner_id' => $partner->id, 'company_id' => $company->id, 'contact_type' => $input['contact_type'] ?? 'general', 'contact_name' => trim($input['contact_name']), 'position' => $input['position'] ?? null, 'email' => isset($input['email']) ? strtolower(trim($input['email'])) : null, 'phone' => $input['phone'] ?? null, 'mobile' => $input['mobile'] ?? null, 'is_primary' => $input['is_primary'] ?? false, 'status' => 'active', 'effective_from' => $input['effective_from'] ?? now()->toDateString(), 'effective_to' => $input['effective_to'] ?? null, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id]);
        $this->record($request, 'business_partner.contact.changed', $partner, $company, [], ['contact_id' => $contact->id, 'contact_name' => $contact->contact_name], null, 'Contact added', 'A business partner contact was added.');

        return $contact;
    }

    public function createAddress(BusinessPartner $partner, array $input, Company $company, Request $request): BusinessPartnerAddress
    {
        if (($input['is_primary_billing'] ?? false)) {
            BusinessPartnerAddress::where('company_id', $company->id)->where('business_partner_id', $partner->id)->where('is_primary_billing', true)->update(['is_primary_billing' => false, 'updated_at' => now()]);
        }
        if (($input['is_primary_shipping'] ?? false)) {
            BusinessPartnerAddress::where('company_id', $company->id)->where('business_partner_id', $partner->id)->where('is_primary_shipping', true)->update(['is_primary_shipping' => false, 'updated_at' => now()]);
        }
        $address = BusinessPartnerAddress::create(['id' => (string) Str::uuid(), 'business_partner_id' => $partner->id, 'company_id' => $company->id, 'address_type' => $input['address_type'] ?? 'general', 'line1' => trim($input['line1']), 'line2' => $input['line2'] ?? null, 'city' => $input['city'] ?? null, 'region' => $input['region'] ?? null, 'postal_code' => $input['postal_code'] ?? null, 'country' => strtoupper($input['country'] ?? 'PH'), 'is_primary_billing' => $input['is_primary_billing'] ?? false, 'is_primary_shipping' => $input['is_primary_shipping'] ?? false, 'status' => 'active', 'effective_from' => $input['effective_from'] ?? now()->toDateString(), 'effective_to' => $input['effective_to'] ?? null, 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id]);
        $this->record($request, 'business_partner.address.changed', $partner, $company, [], ['address_id' => $address->id, 'address_type' => $address->address_type], null, 'Address added', 'A business partner address was added.');

        return $address;
    }

    public function history(string $type, string $id, Company $company)
    {
        return RegistryHistory::where('company_id', $company->id)->where('registry_type', $type)->where('record_id', $id)->latest('created_at')->paginate(25);
    }

    private function partnerDuplicates(Company $company, array $input): array
    {
        $name = RegistryNormalizer::text($input['official_name'] ?? null);
        $email = RegistryNormalizer::text($input['primary_email'] ?? null);
        $phone = RegistryNormalizer::phone($input['primary_phone'] ?? null);
        $tax = RegistryNormalizer::text($input['tax_reference'] ?? null);

        return BusinessPartner::where('company_id', $company->id)->where(function ($query) use ($name, $email, $phone, $tax) {
            if ($name) {
                $query->orWhereRaw('lower(official_name) = ?', [$name]);
            } if ($email) {
                $query->orWhereRaw('lower(primary_email) = ?', [$email]);
            } if ($phone) {
                $query->orWhere('primary_phone', $phone);
            } if ($tax) {
                $query->orWhereRaw('lower(tax_reference) = ?', [$tax]);
            }
        })->limit(5)->get()->map(fn ($item) => ['id' => $item->id, 'code' => $item->code, 'name' => $item->display_name])->all();
    }

    private function syncPartnerRoles(BusinessPartner $partner, Company $company, array $roles, Request $request): void
    {
        $roles = array_values(array_unique($roles));
        $allowed = ['customer', 'supplier', 'payee'];
        if (array_diff($roles, $allowed)) {
            throw new RegistryConflictException('Business partner roles must be customer, supplier, or payee.');
        }
        foreach ($roles as $role) {
            BusinessPartnerRole::updateOrCreate(['business_partner_id' => $partner->id, 'role' => $role], ['id' => (string) Str::uuid(), 'company_id' => $company->id, 'status' => 'active', 'effective_from' => now()->toDateString(), 'created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id]);
        }
        BusinessPartnerRole::where('business_partner_id', $partner->id)->whereNotIn('role', $roles)->where('status', 'active')->update(['status' => 'inactive', 'status_reason' => 'Role removed from governed master record.', 'updated_at' => now()]);
    }

    private function validateProductReferences(array $input, Company $company): void
    {
        $type = $input['record_type'];
        $stock = (bool) ($input['stock_managed'] ?? false);
        if ($type === 'service' && $stock) {
            throw new RegistryConflictException('Services cannot be stock-managed.');
        }
        if ($stock && empty($input['base_unit_id'])) {
            throw new RegistryConflictException('A stock-managed product requires a base unit of measure.');
        }
        if (! empty($input['category_id'])) {
            $category = RegistryCategory::where('company_id', $company->id)->whereKey($input['category_id'])->where('status', 'active')->first();
            if (! $category) {
                throw new RegistryConflictException('The selected category is not active in this company.');
            } if ($category->applicability !== 'both' && $category->applicability !== $type) {
                throw new RegistryConflictException('The selected category is not applicable to this registry type.');
            }
        }
        if (! empty($input['base_unit_id']) && ! UnitOfMeasure::where('company_id', $company->id)->whereKey($input['base_unit_id'])->where('status', 'active')->exists()) {
            throw new RegistryConflictException('The selected unit of measure is not active in this company.');
        }
    }

    private function validateParent(?string $parentId, Company $company, ?string $self = null): void
    {
        if (! $parentId) {
            return;
        } if ($parentId === $self) {
            throw new RegistryConflictException('A category cannot be its own parent.');
        }
        $parent = RegistryCategory::where('company_id', $company->id)->whereKey($parentId)->first();
        if (! $parent) {
            throw new RegistryConflictException('The selected parent category was not found in this company.');
        }
        $visited = [];
        while ($parent) {
            if (in_array($parent->id, $visited, true)) {
                throw new RegistryConflictException('Circular category hierarchy detected.');
            } $visited[] = $parent->id;
            if ($parent->id === $self) {
                throw new RegistryConflictException('A category cannot become a descendant of itself.');
            } $parent = $parent->parent_id ? RegistryCategory::find($parent->parent_id) : null;
        }
    }

    private function syncExternalIdentifiers(Model $record, string $type, Company $company, array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if (empty($identifier['value']) || empty($identifier['identifier_type'])) {
                continue;
            } RegistryExternalIdentifier::updateOrCreate(['company_id' => $company->id, 'registry_type' => $type, 'identifier_type' => RegistryNormalizer::code($identifier['identifier_type']), 'normalized_value' => RegistryNormalizer::text($identifier['value'])], ['id' => (string) Str::uuid(), 'record_id' => $record->id, 'value' => trim($identifier['value']), 'source_system' => $identifier['source_system'] ?? null, 'status' => 'active']);
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
            $record instanceof BusinessPartner => 'business_partner', $record instanceof ProductService => 'product_service', $record instanceof RegistryCategory => 'category', $record instanceof UnitOfMeasure => 'unit', default => 'registry'
        };
    }
}
