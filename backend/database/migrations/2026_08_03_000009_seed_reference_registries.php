<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'master-registries.currencies.view' => 'View Currencies', 'master-registries.currencies.create' => 'Create Currencies', 'master-registries.currencies.update' => 'Update Currencies', 'master-registries.currencies.deactivate' => 'Deactivate Currencies', 'master-registries.currencies.reactivate' => 'Reactivate Currencies',
            'master-registries.payment-methods.view' => 'View Payment Methods', 'master-registries.payment-methods.create' => 'Create Payment Methods', 'master-registries.payment-methods.update' => 'Update Payment Methods', 'master-registries.payment-methods.deactivate' => 'Deactivate Payment Methods', 'master-registries.payment-methods.reactivate' => 'Reactivate Payment Methods',
            'master-registries.payment-terms.view' => 'View Payment Terms', 'master-registries.payment-terms.create' => 'Create Payment Terms', 'master-registries.payment-terms.update' => 'Update Payment Terms', 'master-registries.payment-terms.deactivate' => 'Deactivate Payment Terms', 'master-registries.payment-terms.reactivate' => 'Reactivate Payment Terms',
            'master-registries.tax-codes.view' => 'View Tax Codes', 'master-registries.tax-codes.create' => 'Create Tax Codes', 'master-registries.tax-codes.update' => 'Update Tax Codes', 'master-registries.tax-codes.deactivate' => 'Deactivate Tax Codes', 'master-registries.tax-codes.reactivate' => 'Reactivate Tax Codes',
            'master-registries.account-titles.view' => 'View Account Titles', 'master-registries.account-titles.create' => 'Create Account Titles', 'master-registries.account-titles.update' => 'Update Account Titles', 'master-registries.account-titles.deactivate' => 'Deactivate Account Titles', 'master-registries.account-titles.reactivate' => 'Reactivate Account Titles',
            'master-registries.expense-categories.view' => 'View Expense Categories', 'master-registries.expense-categories.create' => 'Create Expense Categories', 'master-registries.expense-categories.update' => 'Update Expense Categories', 'master-registries.expense-categories.deactivate' => 'Deactivate Expense Categories', 'master-registries.expense-categories.reactivate' => 'Reactivate Expense Categories',
            'master-registries.branches.view' => 'View Branches', 'master-registries.branches.create' => 'Create Branches', 'master-registries.branches.update' => 'Update Branches', 'master-registries.branches.deactivate' => 'Deactivate Branches', 'master-registries.branches.reactivate' => 'Reactivate Branches',
            'master-registries.warehouses.view' => 'View Warehouses', 'master-registries.warehouses.create' => 'Create Warehouses', 'master-registries.warehouses.update' => 'Update Warehouses', 'master-registries.warehouses.deactivate' => 'Deactivate Warehouses', 'master-registries.warehouses.reactivate' => 'Reactivate Warehouses',
            'master-registries.stock-locations.view' => 'View Stock Locations', 'master-registries.stock-locations.create' => 'Create Stock Locations', 'master-registries.stock-locations.update' => 'Update Stock Locations', 'master-registries.stock-locations.deactivate' => 'Deactivate Stock Locations', 'master-registries.stock-locations.reactivate' => 'Reactivate Stock Locations',
            'master-registries.reason-codes.view' => 'View Reason Codes', 'master-registries.reason-codes.create' => 'Create Reason Codes', 'master-registries.reason-codes.update' => 'Update Reason Codes', 'master-registries.reason-codes.deactivate' => 'Deactivate Reason Codes', 'master-registries.reason-codes.reactivate' => 'Reactivate Reason Codes',
        ];
        foreach ($permissions as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'module' => 'master-registries', 'updated_at' => now(), 'created_at' => now()]);
        }
        $ids = DB::table('permissions')->whereIn('key', array_keys($permissions))->pluck('id');
        foreach (DB::table('roles')->whereIn('system_key', ['business_owner', 'administrator'])->pluck('id') as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
        $memberIds = DB::table('permissions')->where('module', 'master-registries')->where(function ($query) {
            $query->whereIn('key', ['master-registries.view', 'master-registries.search', 'master-registries.history'])->orWhere('key', 'like', 'master-registries.%.view');
        })->pluck('id');
        foreach (DB::table('roles')->where('system_key', 'member')->pluck('id') as $roleId) {
            foreach ($memberIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        $currencies = [
            'PHP' => ['Philippine Peso', '₱', 2], 'USD' => ['US Dollar', '$', 2], 'EUR' => ['Euro', '€', 2], 'GBP' => ['Pound Sterling', '£', 2], 'JPY' => ['Japanese Yen', '¥', 0], 'CNY' => ['Yuan Renminbi', '¥', 2], 'SGD' => ['Singapore Dollar', 'S$', 2], 'AUD' => ['Australian Dollar', 'A$', 2], 'CAD' => ['Canadian Dollar', 'C$', 2], 'HKD' => ['Hong Kong Dollar', 'HK$', 2], 'NZD' => ['New Zealand Dollar', 'NZ$', 2], 'INR' => ['Indian Rupee', '₹', 2], 'KRW' => ['South Korean Won', '₩', 0], 'MYR' => ['Malaysian Ringgit', 'RM', 2], 'THB' => ['Thai Baht', '฿', 2], 'IDR' => ['Indonesian Rupiah', 'Rp', 2], 'VND' => ['Vietnamese Dong', '₫', 0], 'CHF' => ['Swiss Franc', 'CHF', 2], 'AED' => ['UAE Dirham', 'د.إ', 2], 'SAR' => ['Saudi Riyal', '﷼', 2],
        ];
        foreach (DB::table('companies')->get(['id', 'currency']) as $company) {
            $code = strtoupper((string) $company->currency);
            if (! isset($currencies[$code])) {
                continue;
            }
            [$name, $symbol, $precision] = $currencies[$code];
            DB::table('reference_currencies')->insertOrIgnore(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'code' => $code, 'normalized_code' => strtolower($code), 'name' => $name, 'symbol' => $symbol, 'decimal_precision' => $precision, 'status' => 'active', 'effective_from' => now()->toDateString(), 'version' => 1, 'system_standard' => true, 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
            $currencyId = DB::table('reference_currencies')->where('company_id', $company->id)->where('normalized_code', strtolower($code))->value('id');
            DB::table('companies')->where('id', $company->id)->whereNull('default_currency_id')->update(['default_currency_id' => $currencyId]);
        }
    }

    public function down(): void
    {
        // Reference data and permission rows are intentionally retained on rollback for safety.
    }
};
