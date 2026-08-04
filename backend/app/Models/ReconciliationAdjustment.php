<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReconciliationAdjustment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'posted_at' => 'datetime', 'version' => 'integer'];
    }

    public function reconciliation()
    {
        return $this->belongsTo(Reconciliation::class);
    }

    public function outstandingItem()
    {
        return $this->belongsTo(ReconciliationOutstandingItem::class);
    }

    public function movementDocument()
    {
        return $this->belongsTo(CashMovementDocument::class, 'cash_movement_document_id');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'record');
    }
}
