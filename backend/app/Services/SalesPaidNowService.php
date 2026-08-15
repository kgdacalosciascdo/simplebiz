<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\Company;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SalesPaidNowService
{
    public function __construct(
        private readonly SalesService $sales,
        private readonly CollectionsService $collections,
    ) {}

    public function complete(Sale $sale, array $input, Company $company, Request $request): array
    {
        return DB::transaction(function () use ($sale, $input, $company, $request) {
            $locked = Sale::where('company_id', $company->id)
                ->whereKey($sale->id)
                ->with(['lines', 'customer', 'currency', 'receivable'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->sale_type !== 'cash_sale' || $locked->payment_basis !== 'cash') {
                throw new RegistryConflictException('Paid-now completion is only available for cash sales.');
            }
            if (! $locked->customer_id || ! $locked->customer) {
                throw new RegistryConflictException('A cash sale must have an identified customer or an active Walk-in customer profile before payment can be recorded.', ['dependency' => 'customer']);
            }
            if ((float) $input['amount'] > (float) $locked->total) {
                throw new RegistryConflictException('Paid-now amount cannot exceed the Sales total.', ['amount' => 'The payment amount must be equal to or less than the sale total.']);
            }
            if (($input['version'] ?? null) !== null && (int) $locked->version !== (int) $input['version']) {
                throw new RegistryConflictException('This Sale was changed by another user. Refresh and try again.', ['version_conflict' => true]);
            }
            if (! in_array($locked->status, ['approved', 'posted'], true)) {
                throw new RegistryConflictException('Only an Approved cash Sale can be completed as paid-now.', ['status' => $locked->status]);
            }

            if ($locked->status === 'approved') {
                $locked = $this->sales->postPaidNowCommercial($locked, $company, $request);
            }

            $receipt = $this->collections->postPaidNow([
                'receipt_date' => $input['receipt_date'],
                'customer_id' => $locked->customer_id,
                'source_sale_id' => $locked->id,
                'currency_id' => $locked->currency_id,
                'amount' => $input['amount'],
                'external_reference' => $input['external_reference'] ?? null,
                'customer_reference' => $input['customer_reference'] ?? null,
                'notes' => $input['notes'] ?? null,
                'tenders' => [[
                    'payment_method_id' => $input['payment_method_id'],
                    'cash_account_id' => $input['cash_account_id'],
                    'amount' => $input['amount'],
                    'external_reference' => $input['external_reference'] ?? null,
                    'instrument_reference' => $input['instrument_reference'] ?? null,
                    'value_date' => $input['value_date'] ?? null,
                    'notes' => $input['notes'] ?? null,
                ]],
            ], $company, $request, $locked);

            return [
                'sale' => $locked->fresh(['lines', 'customer', 'currency', 'paymentTerm', 'receivable', 'statusHistory', 'inventoryMovements', 'salesReturns', 'salesAdjustments', 'receipts']),
                'receipt' => $receipt,
            ];
        });
    }
}
