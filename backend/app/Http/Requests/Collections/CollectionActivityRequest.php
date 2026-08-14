<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollectionActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'uuid'],
            'receipt_id' => ['nullable', 'uuid'],
            'receivable_open_item_id' => ['nullable', 'uuid'],
            'owner_id' => ['nullable', 'integer'],
            'activity_type' => ['required', 'string', 'in:call,email,meeting,promise_to_pay,dispute,follow_up,other'],
            'status' => ['required', 'string', Rule::in(['planned', 'due', 'contacted', 'promise_to_pay', 'disputed', 'escalated', 'resolved', 'cancelled'])],
            'occurred_at' => ['nullable', 'date'],
            'next_action_at' => ['nullable', 'date'],
            'promise_date' => ['nullable', 'date'],
            'promise_amount' => ['nullable', 'numeric', 'gt:0'],
            'dispute_reason' => ['nullable', 'string', 'max:500'],
            'resolution' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'source_reference' => ['nullable', 'string', 'max:180'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
