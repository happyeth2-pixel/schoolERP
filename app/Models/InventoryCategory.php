<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryCategory extends Model
{
    use Audited;
    use SoftDeletes;

    public const KINDS = ['consumable', 'asset'];

    protected $fillable = ['school_id', 'kind', 'name'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
