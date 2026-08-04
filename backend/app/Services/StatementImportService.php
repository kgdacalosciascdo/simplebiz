<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\CashAccount;
use App\Models\Company;
use App\Models\StatementImportBatch;
use App\Models\StatementLine;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StatementImportService
{
    public function __construct(private readonly AuditService $audit, private readonly AttachmentService $attachments, private readonly CashDocumentNumberService $numbers) {}

    public function createBatch(array $input, Company $company, Request $request): StatementImportBatch
    {
        $account = $this->account($input['cash_account_id'] ?? null, $company, 'STATEMENT_IMPORT');
        $this->period($input['period_start'] ?? null, $input['period_end'] ?? null);
        $currency = strtoupper((string) ($input['currency_code'] ?? $account->currency->code));
        if ($currency !== strtoupper($account->currency->code)) {
            throw new RegistryConflictException('The statement currency must match the Cash Account currency.', ['currency' => $account->currency->code]);
        }
        $batch = DB::transaction(function () use ($input, $company, $request, $account, $currency) {
            $batch = StatementImportBatch::create([
                'id' => (string) Str::uuid(), 'company_id' => $company->id, 'cash_account_id' => $account->id,
                'batch_number' => $this->numbers->next($company->id, 'statement_import'), 'provider_reference' => $input['provider_reference'] ?? null,
                'statement_account_identifier_snapshot' => $account->masked_account_identifier ?: $account->external_reference,
                'period_start' => $input['period_start'], 'period_end' => $input['period_end'], 'currency_code' => $currency,
                'status' => 'uploaded', 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id'), 'idempotency_identity' => $request->header('Idempotency-Key'),
                'file_format' => 'manual', 'parser_version' => 'manual-v1', 'imported_by' => $request->user()?->id, 'imported_at' => now(),
            ]);
            $this->audit->record($request, 'cash-account.statement-import.created', $batch, $company->id, [], ['batch_number' => $batch->batch_number, 'period_start' => $batch->period_start->toDateString(), 'period_end' => $batch->period_end->toDateString()], null, 'Statement import created', 'A controlled manual statement import batch was created.');

            return $batch;
        });

        return $this->load($batch);
    }

    public function uploadEvidence(StatementImportBatch $batch, UploadedFile $file, Company $company, Request $request)
    {
        $this->scope($batch, $company);
        if (in_array($batch->status, ['reconciled', 'cancelled'], true)) {
            throw new RegistryConflictException('Completed or cancelled statement evidence cannot be changed.');
        }
        if ($file->getSize() > 10 * 1024 * 1024 || preg_match('/\.(php|phtml|phar|exe|bat|cmd|sh|js|html?)$/i', $file->getClientOriginalName())) {
            throw new RegistryConflictException('The statement evidence file is not permitted.');
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $duplicate = StatementImportBatch::where('company_id', $company->id)->where('cash_account_id', $batch->cash_account_id)->where('period_start', $batch->period_start)->where('period_end', $batch->period_end)->where('file_hash', $hash)->where('id', '<>', $batch->id)->first();
        if ($duplicate) {
            throw new RegistryConflictException('This statement evidence has already been imported for the same account and period.', ['duplicate_batch_id' => $duplicate->id]);
        }
        $attachment = $this->attachments->upload($file, $batch, $company, $request);
        $batch->update(['attachment_id' => $attachment->id, 'original_filename' => $attachment->original_filename, 'file_hash' => $hash, 'version' => $batch->version + 1]);
        $this->audit->record($request, 'cash-account.statement-import.evidence-attached', $batch, $company->id, [], ['attachment_id' => $attachment->id, 'file_hash' => $hash], null, 'Statement evidence attached', 'Private statement evidence was attached to the import batch.');

        return $this->load($batch->refresh());
    }

    public function addLine(StatementImportBatch $batch, array $input, Company $company, Request $request): StatementLine
    {
        $this->scope($batch, $company);
        if (! in_array($batch->status, ['uploaded', 'processing', 'validation_failed'], true)) {
            throw new RegistryConflictException('Statement lines cannot be edited after the batch is ready or reconciled.');
        }
        $date = $input['transaction_date'] ?? null;
        $this->period($date, $date, $batch->period_start->toDateString(), $batch->period_end->toDateString());
        $debit = $this->decimal($input['debit_amount'] ?? '0', 'debit_amount');
        $credit = $this->decimal($input['credit_amount'] ?? '0', 'credit_amount');
        if ($debit !== '0' && $credit !== '0') {
            throw new RegistryConflictException('A statement line cannot contain both debit and credit amounts.');
        }
        if ($debit === '0' && $credit === '0') {
            throw new RegistryConflictException('A statement line requires a positive debit or credit amount.');
        }
        $currency = strtoupper((string) ($input['currency_code'] ?? $batch->currency_code));
        if ($currency !== strtoupper($batch->currency_code)) {
            throw new RegistryConflictException('Statement line currency must match the batch currency.');
        }
        $raw = array_intersect_key($input, array_flip(['transaction_date', 'value_date', 'posting_date', 'description', 'reference', 'counterparty_name', 'external_account_reference', 'debit_amount', 'credit_amount', 'running_balance', 'check_reference', 'provider_transaction_type', 'normalized_transaction_type', 'external_line_id']));
        $fingerprint = hash('sha256', json_encode([$date, $input['reference'] ?? null, $input['description'] ?? '', $debit, $credit, $input['running_balance'] ?? null], JSON_THROW_ON_ERROR));
        $duplicate = StatementLine::where('statement_import_batch_id', $batch->id)->where('fingerprint', $fingerprint)->exists();
        $line = StatementLine::create([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'statement_import_batch_id' => $batch->id, 'cash_account_id' => $batch->cash_account_id,
            'source_row_number' => $input['source_row_number'] ?? (($batch->lines()->max('source_row_number') ?? 0) + 1), 'external_line_id' => $input['external_line_id'] ?? null,
            'transaction_date' => $date, 'value_date' => $input['value_date'] ?? null, 'posting_date' => $input['posting_date'] ?? null, 'description' => trim((string) ($input['description'] ?? 'Manual statement line')),
            'reference' => $input['reference'] ?? null, 'counterparty_name' => $input['counterparty_name'] ?? null, 'external_account_reference' => $input['external_account_reference'] ?? null,
            'debit_amount' => $debit, 'credit_amount' => $credit, 'signed_amount' => $credit === '0' ? '-'.$debit : $credit, 'currency_code' => $currency,
            'running_balance' => $input['running_balance'] ?? null, 'check_reference' => $input['check_reference'] ?? null, 'provider_transaction_type' => $input['provider_transaction_type'] ?? null,
            'normalized_transaction_type' => $input['normalized_transaction_type'] ?? 'other', 'raw_snapshot' => $raw, 'normalization_version' => 'manual-v1', 'fingerprint' => $fingerprint,
            'validation_status' => $duplicate ? 'duplicate' : 'pending', 'validation_errors' => $duplicate ? ['duplicate_line' => 'The same normalized line fingerprint already exists in this batch.'] : null,
        ]);
        $batch->increment('line_count');
        $this->audit->record($request, 'cash-account.statement-line.created', $line, $company->id, [], ['batch_id' => $batch->id, 'fingerprint' => $fingerprint], null, 'Statement line recorded', 'A normalized statement line was recorded without creating a Cash Movement.');

        return $line->refresh();
    }

    public function validateBatch(StatementImportBatch $batch, Company $company, Request $request): StatementImportBatch
    {
        $this->scope($batch, $company);
        if (in_array($batch->status, ['reconciled', 'cancelled'], true)) {
            throw new RegistryConflictException('A completed or cancelled statement batch cannot be revalidated.');
        }
        $lines = $batch->lines()->orderBy('source_row_number')->get();
        $errors = [];
        $seen = [];
        $debit = 0.0;
        $credit = 0.0;
        $previousBalance = $batch->opening_statement_balance !== null ? (float) $batch->opening_statement_balance : null;
        foreach ($lines as $line) {
            $lineErrors = [];
            if (isset($seen[$line->fingerprint])) {
                $lineErrors['duplicate_line'] = 'Duplicate normalized line fingerprint.';
            }
            $seen[$line->fingerprint] = true;
            if ($line->transaction_date->lt($batch->period_start) || $line->transaction_date->gt($batch->period_end)) {
                $lineErrors['transaction_date'] = 'Transaction date is outside the statement period.';
            }
            if ($line->debit_amount > 0 && $line->credit_amount > 0) {
                $lineErrors['amount'] = 'Debit and credit cannot both be populated.';
            }
            if ($line->debit_amount == 0 && $line->credit_amount == 0) {
                $lineErrors['amount'] = 'A debit or credit amount is required.';
            }
            if ($previousBalance !== null && $line->running_balance !== null) {
                $expected = $previousBalance + (float) $line->signed_amount;
                if (abs($expected - (float) $line->running_balance) > 0.000001) {
                    $lineErrors['running_balance'] = 'Running balance continuity failed.';
                }
                $previousBalance = (float) $line->running_balance;
            }
            if ($lineErrors) {
                $errors[$line->source_row_number] = $lineErrors;
            }
            $line->update(['validation_status' => $lineErrors ? (isset($lineErrors['duplicate_line']) ? 'duplicate' : 'invalid') : 'valid', 'validation_errors' => $lineErrors ?: null]);
            $debit += (float) $line->debit_amount;
            $credit += (float) $line->credit_amount;
        }
        if ($batch->opening_statement_balance !== null && $batch->closing_statement_balance !== null) {
            $expectedClosing = (float) $batch->opening_statement_balance + $credit - $debit;
            if (abs($expectedClosing - (float) $batch->closing_statement_balance) > 0.000001) {
                $errors['header']['closing_balance'] = 'Opening balance plus credits less debits does not equal closing balance.';
            }
        }
        $batch->update([
            'status' => $errors ? 'validation_failed' : 'ready', 'version' => $batch->version + 1, 'total_debit' => number_format($debit, 6, '.', ''), 'total_credit' => number_format($credit, 6, '.', ''),
            'parsed_line_count' => $lines->count(), 'rejected_line_count' => count($errors), 'duplicate_line_count' => $lines->where('validation_status', 'duplicate')->count(), 'validation_summary' => ['valid' => ! $errors, 'errors' => $errors, 'sign_convention' => 'positive credit/inflow, negative debit/outflow'],
        ]);
        $this->audit->record($request, 'cash-account.statement-import.validated', $batch, $company->id, [], ['status' => $batch->status, 'line_count' => $lines->count(), 'error_count' => count($errors)], null, 'Statement import validated', $errors ? 'Statement validation failed; no Cash Movement was created.' : 'Statement validation completed and the batch is ready for reconciliation.');

        return $this->load($batch->refresh());
    }

    public function cancel(StatementImportBatch $batch, string $reason, Company $company, Request $request): StatementImportBatch
    {
        $this->scope($batch, $company);
        if (in_array($batch->status, ['reconciled', 'cancelled'], true)) {
            throw new RegistryConflictException('This statement batch cannot be cancelled.');
        }
        if (trim($reason) === '') {
            throw new RegistryConflictException('A cancellation reason is required.');
        }
        $before = $batch->status;
        $batch->update(['status' => 'cancelled', 'version' => $batch->version + 1]);
        $this->audit->record($request, 'cash-account.statement-import.cancelled', $batch, $company->id, ['status' => $before], ['status' => 'cancelled'], $reason, 'Statement import cancelled', 'A statement import batch was cancelled without changing the Cash Movement ledger.');

        return $this->load($batch);
    }

    public function load(StatementImportBatch $batch): StatementImportBatch
    {
        return $batch->load(['account.currency', 'lines', 'attachments']);
    }

    public function account(?string $id, Company $company, string $capability = 'RECONCILE'): CashAccount
    {
        $account = CashAccount::where('company_id', $company->id)->whereKey($id)->with(['currency', 'type'])->firstOrFail();
        if ($account->status !== 'active' || ! $account->type?->supports_reconciliation || ! $account->capabilities()->where('capability', $capability)->where('enabled', true)->exists()) {
            throw new RegistryConflictException('The Cash Account is not eligible for this statement or reconciliation workflow.', ['capability' => $capability]);
        }

        return $account;
    }

    private function scope(StatementImportBatch $batch, Company $company): void
    {
        if ((int) $batch->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The statement batch is outside the current company scope.');
        }
    }

    private function period(?string $start, ?string $end, ?string $minimum = null, ?string $maximum = null): void
    {
        if (! $start || ! $end || $end < $start || ($minimum && $start < $minimum) || ($maximum && $end > $maximum)) {
            throw new RegistryConflictException('The statement period is invalid.');
        }
    }

    private function decimal(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '' || ! preg_match('/^\d+(?:\.\d{1,6})?$/', $value)) {
            throw new RegistryConflictException("The {$field} must be a non-negative decimal with up to six places.");
        }
        $numeric = (float) $value;

        return $numeric == 0.0 ? '0' : number_format($numeric, 6, '.', '');
    }
}
