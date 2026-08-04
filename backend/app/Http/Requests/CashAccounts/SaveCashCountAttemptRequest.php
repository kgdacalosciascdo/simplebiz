<?php

namespace App\Http\Requests\CashAccounts;

use Illuminate\Foundation\Http\FormRequest;

class SaveCashCountAttemptRequest extends FormRequest
{
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'denominations' => ['nullable', 'array'], 'denominations.*.denomination_id' => ['required', 'uuid'], 'denominations.*.quantity' => ['required', 'integer', 'min:0'], 'denominations.*.notes' => ['nullable', 'string', 'max:1000'], 'non_denomination_lines' => ['nullable', 'array'], 'non_denomination_lines.*.item_type' => ['required', 'string', 'max:40'], 'non_denomination_lines.*.description' => ['required', 'string', 'max:180'], 'non_denomination_lines.*.amount' => ['required', 'numeric', 'gt:0'], 'non_denomination_lines.*.notes' => ['nullable', 'string', 'max:1000']];
    }
}
