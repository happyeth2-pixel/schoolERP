<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Student;
use App\Support\PhoneNumber;
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

        // currentEnrollment is already scoped to the student's current-year, active enrollment (see
        // Student::currentEnrollment()), so grade/section/academic year all come from that one relationship --
        // never a second, independently-chosen "which class is this" lookup that could disagree with it.
        $student->load('currentEnrollment.section.grade', 'currentEnrollment.section.academicYear', 'guardians');

        return Inertia::render('IdCard/Show', [
            'student' => $this->cardPayload($student),
            'school' => $student->school->name,
        ]);
    }

    /**
     * Every active student in one class, 2-up (left and right copies of the same card, so a sheet of
     * card stock yields two identical cards to cut apart) -- a registrar printing a whole class never has
     * to open each student one at a time. Access mirrors StudentPolicy::viewRecords() for staff/auditor
     * (never a parent: nobody's own guardian link covers a WHOLE class), checked once against the section's
     * own branch rather than once per student, since every student in it already shares that branch.
     */
    public function batch(Request $request, Section $section): Response
    {
        $user = $request->user();
        $section->loadMissing('branch', 'grade', 'academicYear');
        abort_unless($section->branch->school_id === $user->school_id, 404);
        abort_unless($user->hasRole('auditor') || ($user->can('manage-students') && ($user->isOrgWide() || $user->branch_id === $section->branch_id)), 403);

        $students = $section->enrollments()->active()
            ->with('student.currentEnrollment.section.grade', 'student.currentEnrollment.section.academicYear', 'student.guardians')
            ->get()
            ->pluck('student')
            ->filter()
            ->sortBy(fn (Student $s) => $s->first_name)
            ->values();

        return Inertia::render('IdCard/Batch', [
            'students' => $students->map(fn (Student $s) => $this->cardPayload($s))->values(),
            'school' => $user->school->name,
            'section' => [
                'id' => $section->id,
                'label' => "{$section->grade->name} {$section->name}",
            ],
        ]);
    }

    /** The one shape both the single card and the batch sheet render -- see Components/IdCardFace.jsx. */
    private function cardPayload(Student $student): array
    {
        if (! $student->id_card_token) {
            $student->update(['id_card_token' => Student::newCardToken()]);
        }

        $enrollment = $student->currentEnrollment;

        return [
            'id' => $student->id,
            'name' => $student->fullName(),
            'student_id_number' => $student->student_id_number,
            'photo_url' => $student->photo_path ? Storage::disk('public')->url($student->photo_path) : null,
            'gender' => $student->gender,
            'age' => $student->age(),
            'location' => $this->location($student),
            'mother_name' => $student->mother_name,
            'father_name' => $student->father_name,
            'emergency_contact' => $this->emergencyContact($student),
            'grade' => $enrollment?->section?->grade?->name,
            'section' => $enrollment?->section?->name,
            'academic_year' => $enrollment?->section?->academicYear?->name,
            'card_validity' => $student->idCardValidity(),
            'verify_url' => route('id-card.verify', $student->id_card_token),
        ];
    }

    /** Kebele and city are what an Ethiopian address actually needs to be useful; the older free-text
     * `address` only still shows for a record from before those fields existed. */
    private function location(Student $student): ?string
    {
        $parts = collect([$student->kebele, $student->city])->filter()->values();

        return $parts->isNotEmpty() ? $parts->join(', ') : $student->address;
    }

    /** A linked guardian's own phone is a real, actively-used number on an actual account -- more likely
     * current than a free-text field typed once at registration, so it's preferred when one exists.
     * Mother/father are asked for first (the ones most likely to actually be reachable); any other
     * linked guardian is still better than nothing. Falls back to the plain field, which registration
     * now requires when nobody is linked at all (see StudentController::store()). */
    private function emergencyContact(Student $student): ?string
    {
        $guardian = $student->guardians
            ->sortBy(fn ($g) => in_array($g->pivot->relationship, ['mother', 'father'], true) ? 0 : 1)
            ->first(fn ($g) => filled($g->phone));

        $phone = $guardian?->phone ?? $student->emergency_contact;

        return $phone ? PhoneNumber::display($phone) : null;
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
