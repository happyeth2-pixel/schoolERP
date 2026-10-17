<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;
use App\Policies\Concerns\ScopesToBranch;
use Illuminate\Auth\Access\Response;

class ComplaintPolicy
{
    use ScopesToBranch;

    /**
     * May this person see WHICH CHILD a complaint is about (name, grade, section, siblings)?
     * That is student-record information, so it follows the same rule as the student's
     * own records (StudentPolicy::viewRecords): manage-students in the child's branch,
     * or an auditor. Being able to handle complaints does not, by itself, grant it.
     * The person who filed it always sees their own child.
     */
    public function viewChild(User $user, Complaint $complaint, ?\App\Models\Student $student = null): bool
    {
        $student ??= $complaint->student;

        if (! $student) {
            return false;
        }

        if ($complaint->submitted_by === $user->id) {
            return true;
        }

        return \Illuminate\Support\Facades\Gate::forUser($user)->allows('viewRecords', $student);
    }

    /**
     * May this person see the complainant's contact details (email, phone)?
     * Guardian information sits behind manage-parents in the complaint's branch (auditors read-only).
     */
    public function viewContact(User $user, Complaint $complaint): bool
    {
        if ($complaint->submitted_by === $user->id) {
            return true;
        }

        return ($user->hasRole('auditor') && $user->school_id === $complaint->school_id)
            || ($user->can('manage-parents') && $this->inScope($user, $complaint->school_id, $complaint->branch_id));
    }

    /** The person who filed it, or staff who handle complaints (auditors read-only) in their branch. */
    public function view(User $user, Complaint $complaint): Response
    {
        if ($complaint->submitted_by === $user->id) {
            return Response::allow();
        }

        return $user->canViewOrManage('manage-complaints') && $this->inScope($user, $complaint->school_id, $complaint->branch_id)
            ? Response::allow()
            : $this->deny($user);
    }
}
