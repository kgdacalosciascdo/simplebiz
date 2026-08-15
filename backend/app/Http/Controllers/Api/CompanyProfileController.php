<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferenceRegistryService;
use App\Services\SettingsService;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\CompanyContext;
use App\Support\IdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyProfileController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditService $audit, private readonly IdempotencyService $idempotency, private readonly ReferenceRegistryService $references, private readonly SettingsService $settings) {}

    public function show()
    {
        return ApiResponse::success($this->profilePayload($this->context->get()));
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
            'branding' => ['nullable', 'array'],
            'tax_registration_references' => ['nullable', 'array'],
            'date_format' => ['nullable', 'string', 'max:40'],
            'number_format' => ['nullable', 'string', 'max:40'],
            'paper_size' => ['nullable', 'in:A4,Letter'],
            'document_preferences' => ['nullable', 'array'],
            'reason' => ['nullable', 'string', 'max:500'],
            'profile_version' => ['nullable', 'integer', 'min:1'],
        ]);

        $reason = $input['reason'] ?? null;
        $profileVersion = $input['profile_version'] ?? null;
        unset($input['reason'], $input['profile_version']);

        return $this->idempotency->run($request, 'settings.company.update', $this->context->id(), function () use ($request, $input, $reason, $profileVersion) {
            $company = $this->context->get();
            if ($profileVersion !== null && (int) $company->settings_version !== (int) $profileVersion) {
                return ApiResponse::error('The company configuration changed since this form was loaded. Reload the current version before saving.', 409);
            }
            $defaultFields = ['default_currency_id', 'default_branch_id', 'default_payment_term_id', 'default_payment_method_id', 'default_warehouse_id', 'default_stock_location_id', 'default_expense_category_id', 'default_expense_account_title_id', 'opening_balance_offset_account_title_id'];
            $defaults = array_intersect_key($input, array_flip($defaultFields));
            $profile = array_diff_key($input, array_flip($defaultFields));
            $before = $company->only(array_keys($profile));
            DB::transaction(function () use ($request, $company, $input, $before, $reason) {
                $profile = array_diff_key($input, array_flip(['default_currency_id', 'default_branch_id', 'default_payment_term_id', 'default_payment_method_id', 'default_warehouse_id', 'default_stock_location_id', 'default_expense_category_id', 'default_expense_account_title_id', 'opening_balance_offset_account_title_id']));
                $company->update([...$profile, 'currency' => strtoupper($profile['currency']), 'settings_updated_at' => now()]);
                $this->settings->recordPublishedChange($request, $company->fresh(), $before, $company->fresh()->only(array_keys($profile)), $reason);
                $this->audit->record($request, 'company.profile.updated', $company, $company->id, $before, $company->fresh()->only(array_keys($profile)), null, 'Company profile updated', 'Company setup details were updated.');
            });
            if ($defaults) {
                $this->references->updateCompanyDefaults($defaults, $company->fresh(), $request);
            }

            return ApiResponse::success($this->profilePayload($company->fresh()));
        });
    }

    private function profilePayload($company): array
    {
        return $company->only([
            'id', 'name', 'legal_name', 'primary_contact_name', 'primary_contact_email', 'primary_contact_phone', 'principal_address',
            'currency', 'timezone', 'locale', 'fiscal_year_start_month', 'branding', 'tax_registration_references',
            'date_format', 'number_format', 'paper_size', 'document_preferences', 'settings_version', 'settings_updated_at',
            'default_currency_id', 'default_branch_id', 'default_payment_term_id', 'default_payment_method_id', 'default_warehouse_id',
            'default_stock_location_id', 'default_expense_category_id', 'default_expense_account_title_id', 'opening_balance_offset_account_title_id',
            'opening_balance_lock_date', 'allow_negative_cash_balance', 'negative_balance_requires_approval', 'cash_movement_lock_date',
        ]);
    }
}
