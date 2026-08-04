<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    use HasUuids;

    protected $table = 'attachments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected $hidden = ['stored_path', 'disk'];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function record()
    {
        return $this->morphTo();
    }
}
