<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One issue/return cycle of a `BookCopy` -- academic expansion, Phase 6. `borrower` is polymorphic (a
 * `Student`, issued by staff on their behalf since students have no login account, or a `User` staff
 * borrower) -- the first polymorphic relation in this codebase outside accounting's own unrelated
 * `JournalEntry::source()`. Fines are self-contained: `fine_amount` is only ever resolved by
 * `fine_waived_at`/`fine_paid_at`, both plain timestamps a librarian records directly -- there is no path
 * from here into Invoice/Payment/accounting at all, confirmed with the user at the very start of this
 * initiative ("fines self-contained within Library").
 */
class LibraryLoan extends Model
{
    use Audited;

    protected $fillable = [
        'book_copy_id', 'borrower_type', 'borrower_id', 'issued_by', 'issued_at', 'due_date',
        'returned_at', 'renewed_count', 'status', 'fine_amount', 'fine_waived_at', 'fine_paid_at', 'notes',
        'overdue_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'due_date' => 'date',
            'returned_at' => 'date',
            'fine_amount' => 'decimal:2',
            'fine_waived_at' => 'datetime',
            'fine_paid_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    public function bookCopy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class);
    }

    public function borrower(): MorphTo
    {
        return $this->morphTo();
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function borrowerName(): string
    {
        $b = $this->borrower;

        return $b instanceof Student ? $b->fullName() : ($b?->name ?? 'Unknown');
    }

    public function isOverdue(): bool
    {
        return $this->status === 'active' && $this->due_date->isPast();
    }

    public function auditLabel(): string
    {
        return trim(($this->bookCopy?->book?->title ?? 'Book').' -- '.$this->borrowerName());
    }

    public function auditReason(array $changed): ?string
    {
        return in_array('notes', $changed, true) ? $this->notes : null;
    }
}
