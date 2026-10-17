<?php

namespace App\Models\Concerns;

use App\Exceptions\DeletionBlockedException;

/**
 * For records that ARE the history of the school -- money, marks, attendance,
 * enrollments, students, the audit trail. They are never physically removed:
 * their state changes (cancelled, rejected, withdrawn, ...) instead. A delete
 * attempt through the model fails loudly rather than silently losing data.
 */
trait NeverHardDeleted
{
    public static function bootNeverHardDeleted(): void
    {
        static::deleting(function ($model) {
            throw new DeletionBlockedException(
                class_basename($model).' records are never deleted, because other records and reports depend on their history. Change their status instead (for example withdraw, reject or cancel).'
            );
        });
    }
}
