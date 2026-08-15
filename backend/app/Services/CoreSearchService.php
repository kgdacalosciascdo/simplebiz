<?php

namespace App\Services;

use App\Models\BusinessPartner;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ProductService;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\Request;

final class CoreSearchService
{
    public function search(Request $request, Company $company, User $user, string $term, int $limit = 20): array
    {
        $term = trim($term);
        $limit = min(max($limit, 1), 50);
        if ($term === '') {
            return ['items' => [], 'query' => '', 'limit' => $limit];
        }

        $like = '%'.strtolower($term).'%';
        $items = collect();

        if ($user->hasPermission('master-registries.business-partners.view', $company->id)) {
            $items = $items->merge(BusinessPartner::where('company_id', $company->id)
                ->where(function ($query) use ($like): void {
                    $query->whereRaw('LOWER(code) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(official_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(primary_email) LIKE ?', [$like]);
                })
                ->limit($limit)
                ->get(['id', 'code', 'official_name', 'status'])
                ->map(fn (BusinessPartner $record): array => $this->item('business_partner', $record->id, $record->official_name, $record->code, $record->status, '/master-registries/business-partners/'.$record->id)));
        }

        if ($user->hasPermission('master-registries.items.view', $company->id)) {
            $items = $items->merge(ProductService::where('company_id', $company->id)
                ->where(function ($query) use ($like): void {
                    $query->whereRaw('LOWER(code) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$like]);
                })
                ->limit($limit)
                ->get(['id', 'code', 'name', 'status'])
                ->map(fn (ProductService $record): array => $this->item('product_service', $record->id, $record->name, $record->code, $record->status, '/products-services/'.$record->id)));
        }

        if ($user->hasPermission('sales.view', $company->id)) {
            $items = $items->merge(Sale::where('company_id', $company->id)
                ->where(function ($query) use ($like): void {
                    $query->whereRaw('LOWER(sale_number) LIKE ?', [$like]);
                })
                ->limit($limit)
                ->get(['id', 'sale_number', 'status', 'total'])
                ->map(fn (Sale $record): array => $this->item('sale', $record->id, $record->sale_number, 'Sale', $record->status, '/sales/'.$record->id)));
        }

        if ($user->hasPermission('purchases.view', $company->id)) {
            $items = $items->merge(PurchaseOrder::where('company_id', $company->id)
                ->whereRaw('LOWER(order_number) LIKE ?', [$like])
                ->limit($limit)
                ->get(['id', 'order_number', 'status'])
                ->map(fn (PurchaseOrder $record): array => $this->item('purchase_order', $record->id, $record->order_number, 'Purchase order', $record->status, '/purchases')));
        }

        if ($user->hasPermission('cash-accounts.accounts.view', $company->id)) {
            $items = $items->merge(CashAccount::where('company_id', $company->id)
                ->where(function ($query) use ($like): void {
                    $query->whereRaw('LOWER(code) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$like]);
                })
                ->limit($limit)
                ->get(['id', 'code', 'name', 'status'])
                ->map(fn (CashAccount $record): array => $this->item('cash_account', $record->id, $record->name, $record->code, $record->status, '/cash-accounts')));
        }

        if ($user->hasPermission('expenses.view', $company->id)) {
            $items = $items->merge(Expense::where('company_id', $company->id)
                ->whereRaw('LOWER(expense_number) LIKE ?', [$like])
                ->limit($limit)
                ->get(['id', 'expense_number', 'status'])
                ->map(fn (Expense $record): array => $this->item('expense', $record->id, $record->expense_number, 'Expense', $record->status, '/expenses')));
        }

        return [
            'items' => $items->unique(fn (array $item): string => $item['type'].':'.$item['id'])->take($limit)->values(),
            'query' => $term,
            'limit' => $limit,
        ];
    }

    private function item(string $type, string|int $id, string $label, string $description, ?string $status, string $route): array
    {
        return compact('type', 'id', 'label', 'description', 'status', 'route');
    }
}
