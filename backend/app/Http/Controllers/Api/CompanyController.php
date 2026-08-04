<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\ApiResponse;
use App\Support\AuditService;
use App\Support\CompanyContext;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        return ApiResponse::success($request->user()->companies()->wherePivot('status', 'active')->get()->map(fn ($company) => $this->payload($company))->values());
    }

    public function current()
    {
        return ApiResponse::success($this->payload($this->context->get()));
    }

    public function activate(Request $request, Company $company)
    {
        $membership = $request->user()->companies()->whereKey($company->id)->wherePivot('status', 'active')->first();
        if (! $membership) {
            return ApiResponse::error('The requested company was not found in your active scope.', 404);
        }
        $before = ['preferred_company_id' => $request->user()->preferred_company_id];
        $request->user()->forceFill(['preferred_company_id' => $company->id])->save();
        $request->user()->companies()->updateExistingPivot($company->id, ['last_active_at' => now()]);
        $this->audit->record($request, 'company.context.changed', $company, $company->id, $before, ['preferred_company_id' => $company->id], null, 'Active company changed', "The active company was changed to {$company->name}.");

        return ApiResponse::success($this->payload($company));
    }

    private function payload(?Company $company): array
    {
        return [
            'id' => $company?->id,
            'name' => $company?->name,
            'legal_name' => $company?->legal_name,
            'currency' => $company?->currency,
            'default_currency_id' => $company?->default_currency_id,
            'default_branch_id' => $company?->default_branch_id,
            'default_payment_term_id' => $company?->default_payment_term_id,
            'default_payment_method_id' => $company?->default_payment_method_id,
            'default_warehouse_id' => $company?->default_warehouse_id,
            'default_stock_location_id' => $company?->default_stock_location_id,
            'default_expense_category_id' => $company?->default_expense_category_id,
            'default_expense_account_title_id' => $company?->default_expense_account_title_id,
            'opening_balance_offset_account_title_id' => $company?->opening_balance_offset_account_title_id,
            'opening_balance_lock_date' => $company?->opening_balance_lock_date?->toDateString(),
            'allow_negative_cash_balance' => (bool) $company?->allow_negative_cash_balance,
            'negative_balance_requires_approval' => (bool) $company?->negative_balance_requires_approval,
            'cash_movement_lock_date' => $company?->cash_movement_lock_date?->toDateString(),
            'timezone' => $company?->timezone,
            'locale' => $company?->locale,
            'setup_status' => $company?->setup_status,
            'membership_status' => $company?->pivot?->status,
            'is_owner' => (bool) $company?->pivot?->is_owner,
        ];
    }
}
