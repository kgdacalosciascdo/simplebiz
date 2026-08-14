<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReimbursementApproval extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['submitted_version' => 'integer', 'acted_at' => 'datetime'];
    }

    public function claim()
    {
        return $this->belongsTo(ReimbursementClaim::class, 'reimbursement_claim_id');
    }
}
