<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OtherReceiptType extends Model
{
    use HasUuids;

    protected $table = 'other_receipt_types';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requires_counterparty' => 'boolean', 'requires_source_reference' => 'boolean', 'requires_approval' => 'boolean', 'active' => 'boolean', 'version' => 'integer'];
    }
}
