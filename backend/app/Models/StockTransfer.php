<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['business_date' => 'date', 'posted_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines()
    {
        return $this->hasMany(StockTransferLine::class);
    }
}
