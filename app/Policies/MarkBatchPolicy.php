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
     * Enter or submit marks: the signed-in teacher must (1) hold enter-marks, (2) be in the section's
     * school and branch, (3) CURRENTLY hold the teaching assignment for this section and subject
     * (not merely have created the batch once), and (4) be the batch's owner. Whether the batch is
     * still a draft is checked by the caller under a row lock.
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

        if (! $assigned) {
            return Response::deny('You are not currently assigned to teach this subject for this section.');
        }

        if ($batch->teacher_id !== $user->id) {
            return Response::deny('Another teacher owns this mark batch.');
        }

        return Response::allow();
    }
}
