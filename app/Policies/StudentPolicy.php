<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Policies\Concerns\ScopesToBranch;
use Illuminate\Auth\Access\Response;

/**
 * A student's private records (report card, ID card). A parent gets in ONLY
 * through the guardian link; auditors read everywhere in their school; other
 * staff need manage-students within their branch.
 */
class StudentPolicy
{
    use ScopesToBranch;

    public function viewRecords(User $user, Student $student): Response
    {
        if ($student->school_id !== $user->school_id) {
            return $this->deny($user);
        }

        if ($user->hasRole('parent')) {
            return $user->isGuardianOf($student) ? Response::allow() : $this->deny($user);
        }

        if ($user->hasRole('auditor')) {
            return Response::allow();
        }

        return $user->can('manage-students') && $this->inScope($user, $student->school_id, $student->branch_id)
            ? Response::allow()
            : $this->deny($user);
    }

    /** May this account raise a complaint about this student? Only a linked guardian. */
    public function complainAbout(User $user, Student $student): Response
    {
        return $user->isGuardianOf($student) ? Response::allow() : $this->deny($user);
    }
}
