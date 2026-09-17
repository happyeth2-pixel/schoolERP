<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class IdCardController extends Controller
{
    /**
     * The printable card, shown to whoever is allowed to view this
     * student's records -- same access rule as the report card.
     */
    public function show(Request $request, Student $student): Response
    {
        $this->authorizeView($request->user(), $student);

        if (! $student->id_card_token) {
            $student->update(['id_card_token' => Str::random(40)]);
        }

        return Inertia::render('IdCard/Show', [
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'student_id_number' => $student->student_id_number,
                'status' => $student->status,
                'photo_url' => $student->photo_path ? Storage::disk('public')->url($student->photo_path) : null,
            ],
            'school' => $student->school->name,
            'verifyUrl' => route('id-card.verify', $student->id_card_token),
        ]);
    }

    /**
     * Public, no-login verification page. The QR code on a printed card
     * points here. Deliberately shows nothing but photo/name/status --
     * enough for a guard or teacher to confirm the card belongs to the
     * person holding it, nothing a stranger could use for anything
     * else. A non-active status (withdrawn, expelled, graduated, ...)
     * renders as "not valid" so an old card stops working the moment
     * the student's status changes, with no separate revocation step.
     */
    public function verify(Request $request, string $token): Response
    {
        $student = Student::where('id_card_token', $token)->with('school')->first();

        if (! $student) {
            return Inertia::render('IdCard/Verify', ['found' => false]);
        }

        return Inertia::render('IdCard/Verify', [
            'found' => true,
            'valid' => $student->status === 'active',
            'name' => $student->fullName(),
            'status' => $student->status,
            'photo_url' => $student->photo_path ? Storage::disk('public')->url($student->photo_path) : null,
            'school' => $student->school->name,
        ]);
    }

    private function authorizeView($user, Student $student): void
    {
        abort_unless($student->school_id === $user->school_id, 403);

        if ($user->hasRole('parent')) {
            abort_unless($user->children()->whereKey($student->id)->exists(), 403);

            return;
        }

        if ($user->hasRole('auditor')) {
            return;
        }

        abort_unless($user->can('manage-students'), 403);

        if (! $user->isOrgWide()) {
            abort_unless($student->branch_id === $user->branch_id, 403);
        }
    }
}
