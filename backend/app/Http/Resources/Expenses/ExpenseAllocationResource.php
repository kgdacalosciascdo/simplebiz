<?php

namespace App\Http\Resources\Expenses;

use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseAllocationResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'expense_line_id' => $this->expense_line_id, 'expense_account_title_id' => $this->expense_account_title_id, 'expense_category_id' => $this->expense_category_id, 'branch_id' => $this->branch_id, 'allocation_percent' => (string) $this->allocation_percent, 'amount' => (string) $this->amount, 'status' => $this->status];
    }
}
