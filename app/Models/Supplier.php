<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use Audited;
    use SoftDeletes;

    protected $fillable = [
        'school_id', 'code', 'name', 'contact_person', 'phone', 'email', 'address',
        'tax_number', 'bank_name', 'bank_account_name', 'bank_account_number', 'notes',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }
}
