<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way a journal entry is ever created, posted, or reversed. Mirrors the discipline every other
 * money-moving service in this app already follows (App\Services\Stock, NumberSequence::next()): every
 * check happens INSIDE a transaction, invariants are re-validated at the moment of posting (never trusted
 * from the caller), and the human-facing entry_number is minted last, right before commit.
 *
 * The one invariant this whole engine exists to guarantee: for every POSTED entry,
 * SUM(debit) === SUM(credit). Draft entries may be unbalanced (a work in progress); post() is the one
 * gate that checks and locks this in, in integer cents via Finance::toCents() -- the exact same rounding
 * authority every other money figure in this app already uses, so the ledger can never silently drift
 * from Finance's own computed totals.
 */
class Ledger
{
    private static function nextEntryNumber(int $schoolId): string
    {
        $year = (int) date('Y');
        $prefix = "JE-{$year}-";

        $n = \App\Models\NumberSequence::next($schoolId, 'journal_entry', $year, fn () => JournalEntry::where('school_id', $schoolId)
            ->where('entry_number', 'like', "{$prefix}%")
            ->pluck('entry_number')
            ->map(fn ($x) => (int) substr($x, strlen($prefix)))
            ->max() ?? 0);

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Creates a draft entry with its lines. $lines is a list of
     * ['account_id' => int, 'debit' => float, 'credit' => float, 'description' => ?string].
     * A draft is NOT required to balance yet -- it's a work in progress until post() is called.
     */
    public static function createDraft(School $school, array $attributes, array $lines, User $actor): JournalEntry
    {
        return DB::transaction(function () use ($school, $attributes, $lines, $actor) {
            $period = $school->periodFor($attributes['entry_date']);
            if (! $period) {
                throw ValidationException::withMessages(['entry_date' => 'No accounting period covers this date -- ask someone to open one first.']);
            }

            $entry = JournalEntry::create([
                'school_id' => $school->id,
                'branch_id' => $attributes['branch_id'] ?? null,
                'journal_id' => $attributes['journal_id'],
                'accounting_period_id' => $period->id,
                'entry_number' => self::nextEntryNumber($school->id),
                'entry_date' => $attributes['entry_date'],
                'reference' => $attributes['reference'] ?? null,
                'description' => $attributes['description'] ?? null,
                'source_module' => $attributes['source_module'] ?? 'manual',
                'source_type' => $attributes['source_type'] ?? null,
                'source_id' => $attributes['source_id'] ?? null,
                'status' => 'draft',
                'created_by' => $actor->id,
                'reverses_entry_id' => $attributes['reverses_entry_id'] ?? null,
            ]);

            self::replaceLines($entry, $lines);

            return $entry->fresh('lines');
        });
    }

    /** Creates a draft and immediately posts it -- for system-generated entries (Phase B's invoice/payment
     * postings) where the triggering action already has its own authorization and no separate human review
     * step is needed. Still goes through the exact same balancing/period/account checks as any other post(). */
    public static function createAndPost(School $school, array $attributes, array $lines, User $actor): JournalEntry
    {
        return self::post(self::createDraft($school, $attributes, $lines, $actor), $actor);
    }

    /** Replaces every line of a still-draft entry -- used by createDraft() and by editing a draft. */
    public static function replaceLines(JournalEntry $entry, array $lines): void
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages(['lines' => 'Only a draft journal entry can have its lines changed.']);
        }

        $entry->lines()->delete();
        foreach (array_values($lines) as $index => $line) {
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);
            if (($debit > 0) === ($credit > 0)) {
                throw ValidationException::withMessages(["lines.{$index}.debit" => 'Enter an amount on either the debit or the credit side of this line, never both or neither.']);
            }
            $entry->lines()->create([
                'account_id' => $line['account_id'],
                'line_number' => $index + 1,
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
            ]);
        }
    }

    /**
     * The one invariant gate. Balances in integer cents, checks the period is still open, checks every
     * account used is a postable leaf account (never a header) and in scope for the entry's branch, then
     * flips the entry to posted. Once this returns, the entry is immutable (JournalEntry::booted()).
     */
    public static function post(JournalEntry $entry, User $actor): JournalEntry
    {
        return DB::transaction(function () use ($entry, $actor) {
            $entry = JournalEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only a draft journal entry can be posted.']);
            }

            $lines = $entry->lines()->with('account')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'A journal entry needs at least one line on each side before it can be posted.']);
            }

            $debitCents = $lines->sum(fn ($l) => Finance::toCents($l->debit));
            $creditCents = $lines->sum(fn ($l) => Finance::toCents($l->credit));
            if ($debitCents !== $creditCents) {
                throw ValidationException::withMessages(['lines' => "Debits ({$lines->sum('debit')}) and credits ({$lines->sum('credit')}) must be equal before this entry can be posted."]);
            }
            if ($debitCents === 0) {
                throw ValidationException::withMessages(['lines' => 'A journal entry cannot post with a zero total.']);
            }

            foreach ($lines as $line) {
                if ($line->account->is_header) {
                    throw ValidationException::withMessages(['lines' => "\"{$line->account->name}\" is a header account and cannot be posted to directly -- choose one of its detail accounts."]);
                }
                if (! $line->account->is_active) {
                    throw ValidationException::withMessages(['lines' => "\"{$line->account->name}\" is inactive and cannot be posted to."]);
                }
                if ($line->account->branch_id !== null && $entry->branch_id !== null && $line->account->branch_id !== $entry->branch_id) {
                    throw ValidationException::withMessages(['lines' => "\"{$line->account->name}\" belongs to a different branch than this entry."]);
                }
            }

            $period = \App\Models\AccountingPeriod::whereKey($entry->accounting_period_id)->lockForUpdate()->firstOrFail();
            if ($period->status !== 'open') {
                throw ValidationException::withMessages(['accounting_period_id' => "The accounting period \"{$period->name}\" is closed -- it must be reopened before anything can be posted into it."]);
            }

            $entry->update(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now()]);

            return $entry->fresh('lines');
        });
    }

    /**
     * Corrects a posted entry by creating a brand new entry with every line's debit/credit swapped, dated
     * today (in whatever period is open NOW -- never the original, possibly-closed, period), posted
     * immediately, and linked both ways. The original entry is never edited or deleted.
     */
    public static function reverse(JournalEntry $entry, User $actor, ?string $reason = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $actor, $reason) {
            $entry = JournalEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'Only a posted journal entry can be reversed.']);
            }
            if ($entry->isReversed()) {
                throw ValidationException::withMessages(['status' => 'This journal entry has already been reversed.']);
            }

            $school = $entry->school;
            $today = now()->toDateString();
            $description = $reason ? "Reversal of {$entry->entry_number}: {$reason}" : "Reversal of {$entry->entry_number}";

            $reversal = self::createDraft($school, [
                'branch_id' => $entry->branch_id,
                'journal_id' => $entry->journal_id,
                'entry_date' => $today,
                'reference' => $entry->reference,
                'description' => $description,
                'source_module' => $entry->source_module,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'reverses_entry_id' => $entry->id,
            ], $entry->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
                'debit' => (float) $l->credit,
                'credit' => (float) $l->debit,
                'description' => $l->description,
            ])->all(), $actor);

            $reversal = self::post($reversal, $actor);

            $entry->update(['reversed_by_entry_id' => $reversal->id]);

            return $reversal;
        });
    }
}
