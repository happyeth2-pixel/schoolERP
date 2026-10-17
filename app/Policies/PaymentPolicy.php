<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ScopesToBranch;
use Illuminate\Auth\Access\Response;

class PaymentPolicy
{
    use ScopesToBranch;

    /**
     * The proof image/PDF may show someone's bank details, so a parent sees it
     * only if they submitted it AND are still linked to the student.
     */
    public function viewProof(User $user, Payment $payment): Response
    {
        $student = $payment->invoice->student;

        if ($user->hasRole('parent')) {
            return $payment->submitted_by === $user->id && $user->isGuardianOf($student)
                ? Response::allow()
                : $this->deny($user);
        }

        return $this->staffMayView($user, $student);
    }

    /** The receipt is the school's record for the child's fees: any linked guardian may see it. */
    public function viewReceipt(User $user, Payment $payment): Response
    {
        $student = $payment->invoice->student;

        if ($payment->status !== 'verified') {
            return Response::denyAsNotFound();
        }

        if ($user->hasRole('parent')) {
            return $user->isGuardianOf($student) ? Response::allow() : $this->deny($user);
        }

        return $this->staffMayView($user, $student);
    }

    /** Reversing or refunding a verified payment: only accounts holding refund-payments, in the student's branch. Never a family member. */
    public function refund(User $user, Payment $payment): Response
    {
        if ($user->hasRole('parent') || $user->hasRole('student')) {
            return $this->deny($user);
        }

        return $user->can('refund-payments') && $this->inScope($user, $payment->invoice->student->school_id, $payment->invoice->student->branch_id)
            ? Response::allow()
            : $this->deny($user, 'You are not allowed to refund payments.');
    }

    private function staffMayView(User $user, $student): Response
    {
        return ($user->can('verify-payments') || $user->can('view-financial-reports'))
            && $this->inScope($user, $student->school_id, $student->branch_id)
            ? Response::allow()
            : $this->deny($user);
    }
}
