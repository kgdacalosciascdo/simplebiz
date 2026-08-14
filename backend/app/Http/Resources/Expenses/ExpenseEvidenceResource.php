<?php

namespace App\Http\Resources\Expenses;

use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseEvidenceResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'attachment_id' => $this->attachment_id, 'evidence_type' => $this->evidence_type, 'receipt_reference' => $this->receipt_reference, 'receipt_date' => $this->receipt_date?->toDateString(), 'status' => $this->status, 'requirement_status' => $this->requirement_status, 'notes' => $this->notes, 'attachment' => $this->whenLoaded('attachment', fn () => $this->attachment ? ['id' => $this->attachment->id, 'original_filename' => $this->attachment->original_filename, 'mime_type' => $this->attachment->mime_type, 'file_size' => $this->attachment->file_size, 'file_hash' => $this->attachment->file_hash, 'uploaded_at' => $this->attachment->created_at] : null)];
    }
}
