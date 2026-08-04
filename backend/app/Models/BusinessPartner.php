<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BusinessPartner extends Model
{
    use HasUuids;

    protected $table = 'business_partners';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'status_changed_at' => 'datetime', 'version' => 'integer'];
    }

    public function roles()
    {
        return $this->hasMany(BusinessPartnerRole::class);
    }

    public function contacts()
    {
        return $this->hasMany(BusinessPartnerContact::class);
    }

    public function addresses()
    {
        return $this->hasMany(BusinessPartnerAddress::class);
    }
}
