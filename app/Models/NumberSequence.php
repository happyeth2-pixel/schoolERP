<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class NumberSequence extends Model
{
    protected $fillable = ['school_id', 'sequence_key', 'year', 'last_value'];

    /**
     * Hands out the next number for (school, key, year).
     *
     * The counter row is read with a row lock and incremented in the caller's
     * transaction, so:
     *  - two simultaneous callers are made to queue and get different numbers;
     *  - if the caller's transaction rolls back (e.g. a payment fails its
     *    checks), the increment rolls back too -- no number is used up, no gap;
     *  - a number is only ever consumed by work that actually commits.
     *
     * Call this AFTER every check has passed, as late as possible inside the
     * transaction, to keep the lock short. $seed is only used the first time a
     * sequence is created: it returns the highest number already issued, so
     * numbering carries on from any existing records instead of restarting.
     */
    public static function next(int $schoolId, string $key, int $year, Closure $seed): int
    {
        return DB::transaction(function () use ($schoolId, $key, $year, $seed) {
            // First time only: create the counter row WITHOUT a locking read.
            // (A locking read on a row that doesn't exist yet takes a gap lock,
            // and several requests doing that at once deadlock on the insert.)
            // insertOrIgnore is harmless if someone else creates it first.
            if (! static::where('school_id', $schoolId)->where('sequence_key', $key)->where('year', $year)->exists()) {
                DB::table('number_sequences')->insertOrIgnore([
                    'school_id' => $schoolId,
                    'sequence_key' => $key,
                    'year' => $year,
                    'last_value' => (int) $seed(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $row = static::locked($schoolId, $key, $year);

            $row->last_value = $row->last_value + 1;
            $row->save();

            return (int) $row->last_value;
        });
    }

    private static function locked(int $schoolId, string $key, int $year): ?self
    {
        return static::where('school_id', $schoolId)
            ->where('sequence_key', $key)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();
    }
}
