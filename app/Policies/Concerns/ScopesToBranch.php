<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\Response;

trait ScopesToBranch
{
    /** Same school, and (for branch-scoped staff) same branch. */
    protected function inScope(User $user, int $schoolId, int $branchId): bool
    {
        if ($schoolId !== $user->school_id) {
            return false;
        }

        return $user->isOrgWide() || $user->branch_id === $branchId;
    }

    /**
     * Parents and students get a 404 for anything that isn't theirs, so the
     * response is identical to an ID that doesn't exist: changing an ID in the
     * URL reveals nothing, not even that the record exists. Staff get a normal 403.
     */
    protected function deny(User $user, string $message = 'You are not allowed to view this.'): Response
    {
        return $user->hasRole('parent') || $user->hasRole('student')
            ? Response::denyAsNotFound()
            : Response::deny($message);
    }
}
