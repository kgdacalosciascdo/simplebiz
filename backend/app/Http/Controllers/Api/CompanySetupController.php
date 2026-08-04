<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Permission;
use App\Models\ReferenceCurrency;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\CurrencyCatalog;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CompanySetupController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly IdempotencyService $idempotency) {}

    public function status()
    {
        $company = Company::query()->orderBy('id')->first();

        return ApiResponse::success([
            'required' => $company === null,
            'company' => $company ? ['id' => $company->id, 'name' => $company->name, 'setup_status' => $company->setup_status] : null,
        ]);
    }

    public function bootstrap(Request $request)
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'company_name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'primary_contact_name' => ['nullable', 'string', 'max:120'],
            'primary_contact_email' => ['nullable', 'email', 'max:255'],
            'primary_contact_phone' => ['nullable', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', 'string', 'max:12'],
            'fiscal_year_start_month' => ['nullable', 'integer', 'between:1,12'],
        ]);
        $input['email'] = strtolower($input['email']);

        return $this->idempotency->run($request, 'core.setup.bootstrap', null, function () use ($request, $input) {
            [$user, $company] = DB::transaction(function () use ($request, $input) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('select pg_advisory_xact_lock(?)', [4815162342]);
                }
                if (Company::query()->lockForUpdate()->first()) {
                    return [null, null];
                }
                if (User::where('email', $input['email'])->exists()) {
                    return [null, null];
                }

                $user = User::create([
                    'name' => $input['name'], 'email' => $input['email'],
                    'password' => Hash::make($input['password']), 'status' => 'active',
                ]);
                $company = Company::create([
                    'name' => $input['company_name'],
                    'slug' => Str::slug($input['company_name']).'-'.Str::lower(Str::random(6)),
                    'legal_name' => $input['legal_name'] ?? null,
                    'primary_contact_name' => $input['primary_contact_name'] ?? $input['name'],
                    'primary_contact_email' => $input['primary_contact_email'] ?? $input['email'],
                    'primary_contact_phone' => $input['primary_contact_phone'] ?? null,
                    'currency' => strtoupper($input['currency']), 'timezone' => $input['timezone'],
                    'locale' => $input['locale'], 'fiscal_year_start_month' => $input['fiscal_year_start_month'] ?? null,
                    'status' => 'active', 'setup_status' => 'completed', 'setup_completed_at' => now(),
                    'created_by' => $user->id,
                ]);
                $user->companies()->attach($company->id, ['status' => 'active', 'is_owner' => true, 'last_active_at' => now()]);
                $user->forceFill(['preferred_company_id' => $company->id])->save();

                if ($currency = CurrencyCatalog::get(strtoupper($input['currency']))) {
                    $defaultCurrency = ReferenceCurrency::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => strtoupper($input['currency']), 'normalized_code' => strtolower($input['currency']), 'name' => $currency[0], 'symbol' => $currency[1], 'decimal_precision' => $currency[2], 'status' => 'active', 'effective_from' => now()->toDateString(), 'version' => 1, 'system_standard' => true, 'locked' => false]);
                    $company->forceFill(['default_currency_id' => $defaultCurrency->id])->save();
                }

                $permissions = [
                    'core.manage' => ['Manage Core Platform', 'core'],
                    'settings.company.view' => ['View Company Setup', 'settings'],
                    'settings.company.edit' => ['Edit Company Setup', 'settings'],
                    'settings.users.view' => ['View Users & Access', 'settings'],
                    'settings.users.invite' => ['Invite Users', 'settings'],
                    'settings.users.manage' => ['Manage User Access', 'settings'],
                    'settings.activity.view' => ['View Administrative Activity', 'settings'],
                    'master-registries.view' => ['View Master Registries', 'master-registries'],
                    'master-registries.search' => ['Search Master Registries', 'master-registries'],
                    'master-registries.history' => ['View Master Registry History', 'master-registries'],
                    'master-registries.business-partners.view' => ['View Business Partners', 'master-registries'],
                    'master-registries.business-partners.create' => ['Create Business Partners', 'master-registries'],
                    'master-registries.business-partners.update' => ['Update Business Partners', 'master-registries'],
                    'master-registries.business-partners.deactivate' => ['Deactivate Business Partners', 'master-registries'],
                    'master-registries.business-partners.reactivate' => ['Reactivate Business Partners', 'master-registries'],
                    'master-registries.items.view' => ['View Products and Services', 'master-registries'],
                    'master-registries.items.create' => ['Create Products and Services', 'master-registries'],
                    'master-registries.items.update' => ['Update Products and Services', 'master-registries'],
                    'master-registries.items.deactivate' => ['Deactivate Products and Services', 'master-registries'],
                    'master-registries.items.reactivate' => ['Reactivate Products and Services', 'master-registries'],
                    'master-registries.categories.view' => ['View Product Categories', 'master-registries'],
                    'master-registries.categories.create' => ['Create Product Categories', 'master-registries'],
                    'master-registries.categories.update' => ['Update Product Categories', 'master-registries'],
                    'master-registries.categories.deactivate' => ['Deactivate Product Categories', 'master-registries'],
                    'master-registries.categories.reactivate' => ['Reactivate Product Categories', 'master-registries'],
                    'master-registries.units.view' => ['View Units of Measure', 'master-registries'],
                    'master-registries.units.create' => ['Create Units of Measure', 'master-registries'],
                    'master-registries.units.update' => ['Update Units of Measure', 'master-registries'],
                    'master-registries.units.deactivate' => ['Deactivate Units of Measure', 'master-registries'],
                    'master-registries.units.reactivate' => ['Reactivate Units of Measure', 'master-registries'],
                ];
                $permissionIds = [];
                foreach ($permissions as $key => [$name, $module]) {
                    $permissionIds[] = Permission::firstOrCreate(['key' => $key], ['name' => $name, 'module' => $module])->id;
                }
                $owner = Role::create(['company_id' => $company->id, 'name' => 'Business Owner', 'slug' => 'business-owner', 'system_key' => 'business_owner', 'is_protected' => true, 'status' => 'active']);
                $administrator = Role::create(['company_id' => $company->id, 'name' => 'Administrator', 'slug' => 'administrator', 'system_key' => 'administrator', 'is_protected' => false, 'status' => 'active']);
                $member = Role::create(['company_id' => $company->id, 'name' => 'Member', 'slug' => 'member', 'system_key' => 'member', 'is_protected' => false, 'status' => 'active']);
                $registryPermissionIds = Permission::where('module', 'master-registries')->pluck('id')->all();
                $cashAccountPermissionIds = Permission::where('module', 'cash-accounts')->where(function ($query) {
                    $query->where('key', 'not like', 'cash-accounts.cash-in.%')
                        ->where('key', 'not like', 'cash-accounts.cash-out.%')
                        ->where('key', 'not like', 'cash-accounts.transfers.%')
                        ->where('key', 'not like', 'cash-accounts.cash-counts.%')
                        ->where('key', 'not like', 'cash-accounts.denominations.%')
                        ->where('key', 'not like', 'cash-accounts.variances.%')
                        ->where('key', 'not like', 'cash-accounts.adjustments.%')
                        ->where('key', 'not like', 'cash-accounts.handovers.%')
                        ->where('key', '!=', 'cash-accounts.negative-balance.override');
                })->pluck('id')->all();
                $allCashAccountPermissionIds = Permission::where('module', 'cash-accounts')->pluck('id')->all();
                $cashMovementAdministratorPermissionIds = Permission::whereIn('key', ['cash-accounts.movements.view', 'cash-accounts.movements.history', 'cash-accounts.movements.evidence.view', 'cash-accounts.movements.evidence.download', 'cash-accounts.movements.evidence.upload', 'cash-accounts.cash-in.create', 'cash-accounts.cash-in.update', 'cash-accounts.cash-in.submit', 'cash-accounts.cash-out.create', 'cash-accounts.cash-out.update', 'cash-accounts.cash-out.submit', 'cash-accounts.transfers.view', 'cash-accounts.transfers.create', 'cash-accounts.transfers.update', 'cash-accounts.transfers.submit', 'cash-accounts.denominations.view', 'cash-accounts.cash-counts.view', 'cash-accounts.cash-counts.create', 'cash-accounts.cash-counts.update', 'cash-accounts.cash-counts.start', 'cash-accounts.cash-counts.attempts', 'cash-accounts.cash-counts.submit', 'cash-accounts.cash-counts.evidence.view', 'cash-accounts.cash-counts.evidence.upload', 'cash-accounts.variances.view', 'cash-accounts.adjustments.view', 'cash-accounts.handovers.view', 'cash-accounts.cash-counts.reports.view'])->pluck('id')->all();
                $memberCashPermissionIds = Permission::where('module', 'cash-accounts')->whereIn('key', ['cash-accounts.view', 'cash-accounts.search', 'cash-accounts.history', 'cash-accounts.balance.view', 'cash-accounts.accounts.view', 'cash-accounts.custodians.view', 'cash-accounts.opening-balances.view', 'cash-accounts.evidence.view', 'cash-accounts.denominations.view', 'cash-accounts.cash-counts.view', 'cash-accounts.cash-counts.evidence.view', 'cash-accounts.variances.view', 'cash-accounts.handovers.view', 'cash-accounts.cash-counts.reports.view'])->pluck('id')->all();
                $owner->permissions()->sync(array_values(array_unique([...$permissionIds, ...$registryPermissionIds, ...$allCashAccountPermissionIds])));
                $administrator->permissions()->sync(array_values(array_unique([...$registryPermissionIds, ...$cashAccountPermissionIds, ...$cashMovementAdministratorPermissionIds])));
                $memberRegistryPermissionIds = Permission::where('module', 'master-registries')->where(fn ($query) => $query->whereIn('key', ['master-registries.view', 'master-registries.search', 'master-registries.history'])->orWhere('key', 'like', 'master-registries.%.view'))->pluck('id')->all();
                $member->permissions()->sync(array_values(array_unique([...$memberRegistryPermissionIds, ...$memberCashPermissionIds])));
                DB::table('role_user')->insert(['role_id' => $owner->id, 'user_id' => $user->id, 'company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);

                $request->setUserResolver(fn () => $user);
                $this->audit->record($request, 'setup.completed', $company, $company->id, [], ['setup_status' => 'completed'], null, 'Initial company setup completed', 'The initial company and Business Owner access were created.');

                return [$user, $company];
            });

            if (! $user || ! $company) {
                return ApiResponse::error('Initial company setup has already been completed or the owner email is unavailable.', 409);
            }

            return ApiResponse::success([
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->status],
                'company' => ['id' => $company->id, 'name' => $company->name, 'setup_status' => $company->setup_status],
                'token' => $user->createToken('simplebiz-web', ['*'], now()->addHours(8))->plainTextToken,
            ], 201);
        });
    }
}
