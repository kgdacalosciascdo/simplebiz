<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashMovementDocument extends Model
{
    use HasUuids;

    protected $table = 'cash_movement_documents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'business_date' => 'date', 'posted_at' => 'datetime', 'prepared_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'reversed_at' => 'datetime', 'negative_balance_override' => 'boolean', 'version' => 'integer', 'submitted_version' => 'integer'];
    }

    public function purpose()
    {
        return $this->belongsTo(CashMovementPurpose::class, 'purpose_id');
    }

    public function account()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function currency()
    {
        return $this->belongsTo(ReferenceCurrency::class, 'currency_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function reasonCode()
    {
        return $this->belongsTo(ReasonCode::class, 'reason_code_id');
    }

    public function movement()
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'record', 'record_type', 'record_id');
    }

    public function original()
    {
        return $this->belongsTo(self::class, 'original_document_id');
    }

    public function reversal()
    {
        return $this->belongsTo(self::class, 'reversal_document_id');
    }
}
