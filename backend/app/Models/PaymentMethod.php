<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasUuids;

    protected $table = 'payment_methods';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requires_external_reference' => 'boolean', 'requires_account_selection' => 'boolean', 'supports_incoming' => 'boolean', 'supports_outgoing' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'version' => 'integer'];
    }
}
