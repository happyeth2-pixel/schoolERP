<?php

namespace App\Policies;

use App\Models\MarkBatch;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may open and change a mark batch. Nothing here trusts what the browser sent: the batch is
 * loaded from the database and the person is checked against it.
 */
class MarkBatchPolicy
{
    /**
     * Enter or submit marks: the signed-in person must (1) hold enter-marks, (2) be in the section's
     * school and branch, and (3) have a reason to be touching THIS subject in THIS section -- either
     * they CURRENTLY hold its teaching assignment, or they are the section's own homeroom (class)
     * teacher covering a colleague who cannot use the system (see mayCoverAsHomeroom). Whether the
     * batch is still a draft is checked by the caller under a row lock.
     *
     * Ownership (4) only binds the REGULARLY assigned subject teacher -- it stops an old teacher from
     * saving after a reassignment moved the batch to their replacement. A homeroom teacher covering a
     * subject that isn't their own assignment may always act; the caller makes them the batch's acting
     * teacher the moment they actually save (see MarkBatchController::store()).
     */
    public function enterMarks(User $user, MarkBatch $batch): Response
    {
        if (! $user->can('enter-marks')) {
            return Response::deny('You are not allowed to enter marks.');
        }

        $section = $batch->section()->with('branch')->first();

        if ($section->branch->school_id !== $user->school_id) {
            return Response::deny('This mark batch is not in your school.');
        }

        if (! $user->isOrgWide() && $user->branch_id !== $section->branch_id) {
            return Response::deny('This section is in another branch.');
        }

        $assigned = TeachingAssignment::where('section_id', $batch->section_id)
            ->where('subject_id', $batch->subject_id)
            ->where('teacher_id', $user->id)
            ->exists();

        if (! $assigned && ! self::mayCoverAsHomeroom($user, $section)) {
            return Response::deny('You are not currently assigned to teach this subject for this section.');
        }

        if ($assigned && $batch->teacher_id !== $user->id) {
            return Response::deny('Another teacher owns this mark batch.');
        }

        return Response::allow();
    }

    /** The section's own homeroom (class) teacher may stand in for any of its subject teachers --
     *  it is still one real person's own class, not a blanket "any admin may enter any marks" hole. */
    public static function mayCoverAsHomeroom(User $user, \App\Models\Section $section): bool
    {
        return $user->id === $section->class_teacher_id;
    }
}
