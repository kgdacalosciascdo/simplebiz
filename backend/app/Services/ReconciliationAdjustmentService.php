<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\AccountTitle;
use App\Models\Company;
use App\Models\ReasonCode;
use App\Models\Reconciliation;
use App\Models\ReconciliationAdjustment;
use App\Models\ReconciliationOutstandingItem;
use App\Support\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReconciliationAdjustmentService
{
    public function __construct(private readonly AuditService $audit, private readonly CashDocumentNumberService $numbers, private readonly CashMovementService $movements, private readonly AttachmentService $attachments) {}

    public function create(array $input, Company $company, Request $request): ReconciliationAdjustment
    {
        $reconciliation = Reconciliation::where('company_id', $company->id)->whereKey($input['reconciliation_id'] ?? null)->firstOrFail();
        if (in_array($reconciliation->status, ['completed', 'cancelled'], true)) {
            throw new RegistryConflictException('Adjustments cannot be created on a locked reconciliation.');
        }
        $item = ReconciliationOutstandingItem::where('company_id', $company->id)->where('reconciliation_id', $reconciliation->id)->whereKey($input['outstanding_item_id'] ?? null)->where('status', 'open')->firstOrFail();
        $amount = (float) ($input['amount'] ?? 0);
        if ($amount <= 0 || $amount > (float) $item->amount) {
            throw new RegistryConflictException('The adjustment amount must be positive and cannot exceed the outstanding item.');
        }
        $account = $reconciliation->account()->with('currency')->firstOrFail();
        $offset = AccountTitle::where('company_id', $company->id)->whereKey($input['offset_account_title_id'] ?? null)->where('status', 'active')->firstOrFail();
        $reason = ReasonCode::where('company_id', $company->id)->whereKey($input['reason_code_id'] ?? null)->where('domain', 'CASH_MOVEMENT')->where('status', 'active')->firstOrFail();
        $direction = $input['direction'] ?? 'increase';
        if (! in_array($direction, ['increase', 'decrease'], true)) {
            throw new RegistryConflictException('Adjustment direction is invalid.');
        }
        $date = $input['business_date'] ?? $reconciliation->period_end->toDateString();
        if ($date < $reconciliation->period_start->toDateString() || $date > $reconciliation->period_end->toDateString()) {
            throw new RegistryConflictException('Adjustment date must be within the reconciliation period.');
        }

        return DB::transaction(function () use ($input, $company, $request, $reconciliation, $item, $account, $offset, $reason, $direction, $date, $amount) {
            $adjustment = ReconciliationAdjustment::create(['id' => (string) Str::uuid(), 'company_id' => $company->id, 'reconciliation_id' => $reconciliation->id, 'outstanding_item_id' => $item->id, 'adjustment_number' => $this->numbers->next($company->id, 'reconciliation_adjustment'), 'direction' => $direction, 'amount' => number_format($amount, 6, '.', ''), 'offset_account_title_id' => $offset->id, 'reason_code_id' => $reason->id, 'explanation' => $input['explanation'], 'status' => 'draft', 'prepared_by' => $request->user()?->id, 'version' => 1, 'correlation_id' => $request->attributes->get('correlation_id')]);
            $document = $this->movements->create(['movement_purpose' => $direction === 'increase' ? 'DIRECT_CASH_IN' : 'DIRECT_CASH_OUT', 'cash_account_id' => $account->id, 'currency_id' => $account->currency_id, 'amount' => number_format($amount, 6, '.', ''), 'business_date' => $date, 'source_type' => 'CASH_ADJUSTMENT', 'source_record_type' => ReconciliationAdjustment::class, 'source_record_id' => $adjustment->id, 'external_reference' => $adjustment->adjustment_number, 'offset_account_title_id' => $offset->id, 'reason_code_id' => $reason->id, 'explanation' => $input['explanation']], $company, $request, $direction === 'increase' ? 'cash_in' : 'cash_out');
            $adjustment->update(['cash_movement_document_id' => $document->id]);
            $this->audit->record($request, 'cash-account.reconciliation-adjustment.created', $adjustment, $company->id, [], ['adjustment_number' => $adjustment->adjustment_number, 'outstanding_item_id' => $item->id, 'movement_document_id' => $document->id], null, 'Reconciliation adjustment prepared', 'A reconciliation adjustment was prepared through the existing governed Cash Movement source template.');

            return $adjustment;
        });
    }

    public function uploadEvidence(ReconciliationAdjustment $adjustment, UploadedFile $file, Company $company, Request $request): ReconciliationAdjustment
    {
        $this->scope($adjustment, $company);
        $this->editable($adjustment);
        $this->attachments->upload($file, $adjustment, $company, $request);
        if ($adjustment->movementDocument) {
            $this->attachments->upload($file, $adjustment->movementDocument, $company, $request);
        }

        return $adjustment->load(['attachments', 'movementDocument.attachments']);
    }

    public function submit(ReconciliationAdjustment $adjustment, Company $company, Request $request): ReconciliationAdjustment
    {
        $this->scope($adjustment, $company);
        $this->editable($adjustment);
        if (! $adjustment->attachments()->exists()) {
            throw new RegistryConflictException('Adjustment evidence is required before submission.');
        } $document = $adjustment->movementDocument()->firstOrFail();
        $this->movements->submit($document, $company, $request);
        $adjustment->update(['status' => 'submitted', 'version' => $adjustment->version + 1]);

        return $this->load($adjustment->refresh());
    }

    public function approve(ReconciliationAdjustment $adjustment, Company $company, Request $request): ReconciliationAdjustment
    {
        $this->scope($adjustment, $company);
        if ($adjustment->status !== 'submitted') {
            throw new RegistryConflictException('Only submitted reconciliation adjustments may be approved.');
        } $document = $adjustment->movementDocument()->firstOrFail();
        $this->movements->approve($document, $company, $request);
        $adjustment->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'version' => $adjustment->version + 1]);

        return $this->load($adjustment->refresh());
    }

    public function post(ReconciliationAdjustment $adjustment, Company $company, Request $request): ReconciliationAdjustment
    {
        $this->scope($adjustment, $company);
        if ($adjustment->status !== 'approved') {
            throw new RegistryConflictException('Only approved reconciliation adjustments may be posted.');
        } $document = $adjustment->movementDocument()->firstOrFail();
        $this->movements->post($document, $company, $request);
        $adjustment->update(['status' => 'posted', 'posted_by' => $request->user()?->id, 'posted_at' => now(), 'version' => $adjustment->version + 1]);

        return $this->load($adjustment->refresh());
    }

    public function reverse(ReconciliationAdjustment $adjustment, string $reason, Company $company, Request $request): ReconciliationAdjustment
    {
        $this->scope($adjustment, $company);
        if ($adjustment->status !== 'posted' || ! $adjustment->movementDocument) {
            throw new RegistryConflictException('Only posted reconciliation adjustments may be reversed.');
        } $this->movements->reverse($adjustment->movementDocument, $reason, $company, $request);
        $adjustment->update(['status' => 'reversed', 'version' => $adjustment->version + 1]);

        return $this->load($adjustment->refresh());
    }

    public function load(ReconciliationAdjustment $adjustment): ReconciliationAdjustment
    {
        return $adjustment->load(['reconciliation', 'outstandingItem', 'movementDocument', 'attachments']);
    }

    private function scope(ReconciliationAdjustment $adjustment, Company $company): void
    {
        if ((int) $adjustment->company_id !== (int) $company->id) {
            throw new RegistryConflictException('The adjustment is outside the current company scope.');
        }
    }

    private function editable(ReconciliationAdjustment $adjustment): void
    {
        if ($adjustment->status !== 'draft') {
            throw new RegistryConflictException('Only draft reconciliation adjustments may be edited.');
        }
    }
}
