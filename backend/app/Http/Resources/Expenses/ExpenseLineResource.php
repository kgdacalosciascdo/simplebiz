<?php

namespace App\Http\Resources\Expenses;

use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'line_number' => $this->line_number, 'expense_category_id' => $this->expense_category_id, 'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'code' => $this->category->code, 'name' => $this->category->name] : null), 'expense_account_title_id' => $this->expense_account_title_id, 'account' => $this->whenLoaded('account', fn () => $this->account ? ['id' => $this->account->id, 'code' => $this->account->code, 'name' => $this->account->name, 'locked' => $this->account->locked] : null), 'description' => $this->description, 'quantity' => (string) $this->quantity, 'unit_amount' => (string) $this->unit_amount, 'line_amount' => (string) $this->line_amount, 'tax_code_id' => $this->tax_code_id, 'tax_code' => $this->whenLoaded('taxCode', fn () => $this->taxCode ? ['id' => $this->taxCode->id, 'code' => $this->taxCode->code, 'name' => $this->taxCode->name, 'rate' => (string) $this->taxCode->rate, 'basis' => $this->taxCode->basis, 'recoverable' => $this->taxCode->recoverable] : null), 'tax_basis' => $this->tax_basis, 'taxable_amount' => (string) $this->taxable_amount, 'tax_amount' => (string) $this->tax_amount, 'recoverable_tax_amount' => (string) $this->recoverable_tax_amount, 'nonrecoverable_tax_amount' => (string) $this->nonrecoverable_tax_amount, 'withholding_amount' => (string) $this->withholding_amount, 'line_total' => (string) $this->line_total, 'branch_id' => $this->branch_id, 'version' => $this->version];
    }
}
