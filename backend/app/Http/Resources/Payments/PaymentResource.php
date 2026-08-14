<?php

namespace App\Http\Resources\Payments;

use App\Http\Resources\CashAccounts\AttachmentResource;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'payment_request_id' => $this->payment_request_id,
            'source_kind' => $this->source_kind,
            'payment_number' => $this->payment_number,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'code' => $this->supplier->code, 'display_name' => $this->supplier->display_name]),
            'branch_id' => $this->branch_id,
            'currency_id' => $this->currency_id,
            'currency' => $this->whenLoaded('currency', fn () => ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol]),
            'payment_method_id' => $this->payment_method_id,
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => ['id' => $this->paymentMethod->id, 'code' => $this->paymentMethod->code, 'name' => $this->paymentMethod->name, 'method_class' => $this->paymentMethod->method_class, 'clearing_behavior' => $this->paymentMethod->clearing_behavior]),
            'cash_account_id' => $this->cash_account_id,
            'cash_account' => $this->whenLoaded('cashAccount', fn () => ['id' => $this->cashAccount->id, 'code' => $this->cashAccount->code, 'name' => $this->cashAccount->name, 'currency_id' => $this->cashAccount->currency_id]),
            'payment_date' => $this->payment_date?->toDateString(),
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'gross_amount' => (string) $this->gross_amount,
            'discount_amount' => (string) $this->discount_amount,
            'withholding_amount' => (string) $this->withholding_amount,
            'fee_amount' => (string) $this->fee_amount,
            'tax_amount' => (string) $this->tax_amount,
            'net_amount' => (string) $this->net_amount,
            'confirmed_amount' => (string) $this->confirmed_amount,
            'allocated_amount' => (string) $this->allocated_amount,
            'unapplied_amount' => (string) $this->unapplied_amount,
            'reference' => $this->reference,
            'remittance_details' => $this->remittance_details,
            'evidence_reference' => $this->evidence_reference,
            'status' => $this->status,
            'execution_state' => $this->execution_state,
            'confirmation_state' => $this->confirmation_state,
            'allocation_state' => $this->allocation_state,
            'instrument_type' => $this->instrument_type,
            'external_reference' => $this->external_reference,
            'failure_reason' => $this->failure_reason,
            'sources' => PaymentSourceResource::collection($this->whenLoaded('sources')),
            'approvals' => PaymentApprovalResource::collection($this->whenLoaded('approvals')),
            'instruments' => PaymentInstrumentResource::collection($this->whenLoaded('instruments')),
            'attempts' => PaymentExecutionAttemptResource::collection($this->whenLoaded('attempts')),
            'confirmations' => PaymentConfirmationResource::collection($this->whenLoaded('confirmations')),
            'allocations' => PaymentAllocationResource::collection($this->whenLoaded('allocations')),
            'status_history' => $this->whenLoaded('statusHistory'),
            'remittance_advice' => $this->whenLoaded('remittanceAdvice', fn () => new RemittanceAdviceResource($this->remittanceAdvice)),
            'advance' => $this->whenLoaded('advance', fn () => $this->advance ? new PaymentAdvanceResource($this->advance) : null),
            'corrections' => PaymentCorrectionResource::collection($this->whenLoaded('corrections')),
            'batch_items' => $this->whenLoaded('batchItems'),
            'voucher' => $this->whenLoaded('voucher', fn () => $this->voucher ? new PaymentVoucherResource($this->voucher) : null),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'cash_movement_id' => $this->cash_movement_id,
            'accounting_transaction_id' => $this->accounting_transaction_id,
            'version' => $this->version,
        ];
    }
}
