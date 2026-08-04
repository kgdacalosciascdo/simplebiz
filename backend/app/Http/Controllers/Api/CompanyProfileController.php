<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferenceRegistryService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyProfileController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditService $audit, private readonly IdempotencyService $idempotency, private readonly ReferenceRegistryService $references) {}

    public function show()
    {
        return ApiResponse::success($this->context->get());
    }

    public function update(Request $request)
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'primary_contact_name' => ['nullable', 'string', 'max:120'],
            'primary_contact_email' => ['nullable', 'email', 'max:255'],
            'primary_contact_phone' => ['nullable', 'string', 'max:40'],
            'principal_address' => ['nullable', 'array'],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', 'string', 'max:12'],
            'fiscal_year_start_month' => ['nullable', 'integer', 'between:1,12'],
            'default_currency_id' => ['nullable', 'uuid'],
            'default_branch_id' => ['nullable', 'uuid'],
            'default_payment_term_id' => ['nullable', 'uuid'],
            'default_payment_method_id' => ['nullable', 'uuid'],
            'default_warehouse_id' => ['nullable', 'uuid'],
            'default_stock_location_id' => ['nullable', 'uuid'],
            'default_expense_category_id' => ['nullable', 'uuid'],
            'default_expense_account_title_id' => ['nullable', 'uuid'],
            'opening_balance_offset_account_title_id' => ['nullable', 'uuid'],
            'opening_balance_lock_date' => ['nullable', 'date'],
            'allow_negative_cash_balance' => ['sometimes', 'boolean'],
            'negative_balance_requires_approval' => ['sometimes', 'boolean'],
            'cash_movement_lock_date' => ['nullable', 'date'],
        ]);

        return $this->idempotency->run($request, 'settings.company.update', $this->context->id(), function () use ($request, $input) {
            $company = $this->context->get();
            $defaultFields = ['default_currency_id', 'default_branch_id', 'default_payment_term_id', 'default_payment_method_id', 'default_warehouse_id', 'default_stock_location_id', 'default_expense_category_id', 'default_expense_account_title_id', 'opening_balance_offset_account_title_id'];
            $defaults = array_intersect_key($input, array_flip($defaultFields));
            $profile = array_diff_key($input, array_flip($defaultFields));
            $before = $company->only(array_keys($profile));
            DB::transaction(function () use ($request, $company, $input, $before) {
                $profile = array_diff_key($input, array_flip(['default_currency_id', 'default_branch_id', 'default_payment_term_id', 'default_payment_method_id', 'default_warehouse_id', 'default_stock_location_id', 'default_expense_category_id', 'default_expense_account_title_id', 'opening_balance_offset_account_title_id']));
                $company->update([...$profile, 'currency' => strtoupper($profile['currency'])]);
                $this->audit->record($request, 'company.profile.updated', $company, $company->id, $before, $company->fresh()->only(array_keys($profile)), null, 'Company profile updated', 'Company setup details were updated.');
            });
            if ($defaults) {
                $this->references->updateCompanyDefaults($defaults, $company->fresh(), $request);
            }

            return ApiResponse::success($company->fresh());
        });
    }
}
