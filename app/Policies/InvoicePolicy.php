<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ScopesToBranch;
use Illuminate\Auth\Access\Response;

class InvoicePolicy
{
    use ScopesToBranch;

    public function view(User $user, Invoice $invoice): Response
    {
        $student = $invoice->student;

        if ($user->hasRole('parent')) {
            return $student->school_id === $user->school_id && $user->isGuardianOf($student)
                ? Response::allow()
                : $this->deny($user);
        }

        return ($user->can('manage-invoices') || $user->can('view-financial-reports'))
            && $this->inScope($user, $student->school_id, $student->branch_id)
            ? Response::allow()
            : $this->deny($user);
    }

    /**
     * Recording money received at the school: staff who hold verify-payments (the same authority that accepts a
     * parent's proof), in the student's school and branch. Never a family member, and never someone who can only read.
     */
    public function record(User $user, Invoice $invoice): Response
    {
        $student = $invoice->student;

        return ! $user->hasRole('parent') && ! $user->hasRole('student')
            && $user->can('verify-payments')
            && $this->inScope($user, $student->school_id, $student->branch_id)
            ? Response::allow()
            : $this->deny($user, 'You are not allowed to record payments.');
    }

    /** Submitting a payment proof against an invoice: only a linked guardian. */
    public function pay(User $user, Invoice $invoice): Response
    {
        return $user->hasRole('parent') && $user->isGuardianOf($invoice->student_id)
            ? Response::allow()
            : $this->deny($user);
    }
}
