<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A configurable grouping for journal entries -- not a hardcoded list. A school gets the seven common
 * ones (cash, bank, sales, purchase, general, receivable, payable) installed alongside its chart of
 * accounts (see App\Support\ChartOfAccountsTemplate) and can add more. */
class Journal extends Model
{
    use Audited;

    public const TYPES = ['cash', 'bank', 'sales', 'purchase', 'general', 'receivable', 'payable'];

    protected $fillable = ['school_id', 'code', 'name', 'type', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleting(function (Journal $journal) {
            if ($journal->is_system) {
                throw new DeletionBlockedException('A system journal cannot be removed.');
            }
            if ($journal->entries()->exists()) {
                throw new DeletionBlockedException('A journal that already has entries cannot be removed -- deactivate it instead.');
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function auditLabel(): string
    {
        return "{$this->code} {$this->name}";
    }
}
