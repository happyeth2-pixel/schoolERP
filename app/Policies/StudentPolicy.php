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

    /** Read-only: a guardian, the auditor, or anyone who could send (see sendMessage). */
    public function viewMessages(User $user, Student $student): Response
    {
        if ($user->isGuardianOf($student)) {
            return Response::allow();
        }

        if ($user->hasRole('auditor') && $student->school_id === $user->school_id) {
            return Response::allow();
        }

        return $this->sendMessage($user, $student);
    }

    /**
     * May this account send a private message about this student to their guardians? A guardian always can.
     * Staff need `parent-messages` within scope, AND -- for a plain teacher -- to be the CURRENT homeroom
     * teacher of the student's section: a subject teacher with no homeroom tie has no reason to privately
     * message a family, the same restriction attendance itself already enforces. A student with no current
     * section at all (registration deferred placement) has no homeroom teacher yet, so only a branch
     * admin/owner can reach out until they are placed.
     */
    public function sendMessage(User $user, Student $student): Response
    {
        if ($user->isGuardianOf($student)) {
            return Response::allow();
        }

        if ($student->school_id !== $user->school_id || ! $user->can('parent-messages')) {
            return $this->deny($user);
        }

        if (! $this->inScope($user, $student->school_id, $student->branch_id)) {
            return $this->deny($user);
        }

        if ($user->hasRole('teacher')) {
            $section = $student->currentEnrollment?->section;

            return $section && $section->class_teacher_id === $user->id
                ? Response::allow()
                : $this->deny($user, 'Only this class\'s homeroom teacher, a branch admin, or the owner can message this family.');
        }

        return Response::allow();
    }
}
