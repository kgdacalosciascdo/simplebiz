<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'owner_module' => $this->owner_module, 'record_type' => $this->record_type, 'record_id' => $this->record_id, 'original_filename' => $this->original_filename, 'mime_type' => $this->mime_type, 'file_size' => $this->file_size, 'file_hash' => $this->file_hash, 'sensitivity' => $this->sensitivity, 'status' => $this->status, 'uploaded_by' => $this->uploaded_by, 'created_at' => $this->created_at];
    }
}
