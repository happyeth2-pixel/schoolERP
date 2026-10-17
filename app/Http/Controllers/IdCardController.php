<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
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
        Gate::authorize('viewRecords', $student);

        if (! $student->id_card_token) {
            $student->update(['id_card_token' => Student::newCardToken()]);
        }

        return Inertia::render('IdCard/Show', [
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'student_id_number' => $student->student_id_number,
                'photo_url' => $student->photo_path ? Storage::disk('public')->url($student->photo_path) : null,
            ],
            'school' => $student->school->name,
            'cardValidity' => $student->idCardValidity(),
            'verifyUrl' => route('id-card.verify', $student->id_card_token),
        ]);
    }

    /**
     * Public, no-login verification page -- what a card's QR code opens.
     *
     * It answers with one of four simple states, calculated from the student's
     * status and enrollment (Student::idCardValidity()): Valid, Expired,
     * Suspended or Invalid. Only VALID shows anything about the person (photo,
     * first + father's name, school); the other three show just the state and
     * an instruction. Withdrawn, expelled, transferred, inactive and unknown
     * codes all collapse into the same generic "Invalid" so no more reason than
     * needed is ever disclosed. Only UNKNOWN codes count toward the miss limit,
     * so a gate guard scanning a stack of expired cards is never throttled.
     */
    public function verify(Request $request, string $token): Response
    {
        $missKey = 'id-verify-miss:'.$request->ip();

        if (RateLimiter::tooManyAttempts($missKey, 8)) {
            abort(429, 'Too many invalid attempts. Please wait a minute.');
        }

        $student = Student::where('id_card_token', $token)->with('school:id,name')->first();

        if (! $student) {
            RateLimiter::hit($missKey, 60);

            return Inertia::render('IdCard/Verify', ['state' => 'invalid']);
        }

        $state = $student->idCardState();

        if ($state !== 'valid') {
            return Inertia::render('IdCard/Verify', ['state' => $state]);
        }

        return Inertia::render('IdCard/Verify', [
            'state' => 'valid',
            'name' => $student->publicName(),
            'photo_url' => $student->photo_path ? Storage::disk('public')->url($student->photo_path) : null,
            'school' => $student->school->name,
        ]);
    }
}
