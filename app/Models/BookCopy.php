<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\GuardsDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical copy of a `Book` -- branch-scoped (the same split `Room` already uses). `status` is only
 * ever changed by `App\Services\Library`, the one gateway for issue/return/renew/lost/damaged, mirroring the
 * single-writer-service discipline `Stock`/`Ledger`/`SectionCapacity` already established elsewhere.
 */
class BookCopy extends Model
{
    use Audited;
    use GuardsDeletion;

    protected $fillable = ['book_id', 'branch_id', 'copy_number', 'status', 'created_by'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(LibraryLoan::class);
    }

    public function activeLoan(): HasMany
    {
        return $this->loans()->where('status', 'active');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deletionBlockers(): array
    {
        $blockers = [];
        if (($count = $this->loans()->count()) > 0) {
            $blockers[] = "{$count} loan record(s)";
        }

        return $blockers;
    }

    public function auditLabel(): string
    {
        return trim(($this->book?->title ?? 'Book #'.$this->book_id).' -- #'.$this->copy_number);
    }
}
