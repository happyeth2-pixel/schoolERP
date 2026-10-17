<?php

namespace App\Models\Concerns;

use App\Exceptions\DeletionBlockedException;

/**
 * For set-up records (grade, section, subject, exam, term...). They may be
 * removed only while nothing depends on them; once they have history,
 * deletion is refused with a plain explanation of what still uses them.
 *
 * The model lists what still depends on it in deletionBlockers().
 */
trait GuardsDeletion
{
    /** @return list<string> e.g. ["3 sections", "12 enrolled students"] -- empty when it is safe to delete */
    abstract public function deletionBlockers(): array;

    public static function bootGuardsDeletion(): void
    {
        static::deleting(function ($model) {
            $blockers = $model->deletionBlockers();

            if ($blockers !== []) {
                throw new DeletionBlockedException(
                    'This '.strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($model))).' cannot be deleted because it is still used by: '.implode(', ', $blockers).'.'
                );
            }
        });
    }
}
