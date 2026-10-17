<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One side of one account's movement within a journal entry -- meaningless on its own, only ever changed
 * through its parent JournalEntry while that entry is still a draft. Once the parent is posted, its lines
 * are frozen along with it (checked here, against the parent's OWN status, since a line has no status of
 * its own). */
class JournalEntryLine extends Model
{
    protected $fillable = ['journal_entry_id', 'account_id', 'line_number', 'debit', 'credit', 'description'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (JournalEntryLine $line) {
            $entry = $line->journalEntry ?? JournalEntry::find($line->journal_entry_id);
            if ($entry && $entry->status !== 'draft') {
                throw new DeletionBlockedException('Lines of a posted journal entry cannot be changed.');
            }
        });

        static::deleting(function (JournalEntryLine $line) {
            if ($line->journalEntry && $line->journalEntry->status !== 'draft') {
                throw new DeletionBlockedException('Lines of a posted journal entry cannot be removed.');
            }
        });
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
