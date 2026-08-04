<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashTransferDocument extends Model
{
    use HasUuids;

    protected $table = 'cash_transfer_documents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'business_date' => 'date', 'expected_completion_date' => 'date', 'prepared_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'reversed_at' => 'datetime', 'version' => 'integer', 'submitted_version' => 'integer'];
    }

    public function sourceAccount()
    {
        return $this->belongsTo(CashAccount::class, 'source_cash_account_id');
    }

    public function destinationAccount()
    {
        return $this->belongsTo(CashAccount::class, 'destination_cash_account_id');
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

    public function legs()
    {
        return $this->hasMany(CashTransferLeg::class, 'transfer_document_id');
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
