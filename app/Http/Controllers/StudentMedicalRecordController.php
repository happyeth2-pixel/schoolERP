<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Student;
use App\Models\StudentMedicalRecord;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A student's medical profile -- academic expansion, Phase 4. Deliberately its OWN controller/route/page,
 * never folded into the ordinary `Students/Edit` form or the student list: `medical-records-view`/`manage`
 * are separate permissions from `manage-students`, so holding the broad student-admin permission alone must
 * never reveal health data. `StudentController::index()`'s list payload and `edit()`'s own props never
 * include a single medical field -- only a `canViewMedical` capability flag, so even inspecting that page's
 * own JSON reveals nothing about the student's health.
 */
class StudentMedicalRecordController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('medical-records-view') || $user->can('medical-records-manage') || $user->hasRole('auditor');
    }

    private function assertInScope(User $user, Student $student): void
    {
        abort_unless($student->school_id === $user->school_id, 403);

        if (! $user->isOrgWide()) {
            abort_unless($student->branch_id === $user->branch_id, 403);
        }
    }

    /**
     * A triage-style overview for the principal/owner, mirroring Discipline's own Index page: WHICH students
     * have something on file, never the content itself (allergies/conditions/medications stay text-only on
     * the per-student page, same privacy discipline as everywhere else in this controller) -- a flag here is
     * no more exposure than what medical-records-view already unlocks per student, just aggregated so it
     * doesn't take opening every profile to see who has anything recorded at all.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $branchIds = $user->isOrgWide() ? $user->school->branches()->pluck('id') : collect([$user->branch_id]);

        $students = Student::whereIn('branch_id', $branchIds)
            ->enrolled()
            ->whereHas('medicalRecord')
            ->with(['medicalRecord', 'currentEnrollment.section.grade'])
            ->orderBy('first_name')
            ->get()
            ->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->fullName(),
                'class' => $s->currentEnrollment ? "{$s->currentEnrollment->section->grade->name} {$s->currentEnrollment->section->name}" : null,
                'blood_type' => $s->medicalRecord->blood_type,
                'has_allergies' => filled($s->medicalRecord->allergies),
                'has_conditions' => filled($s->medicalRecord->conditions),
                'has_medications' => filled($s->medicalRecord->medications),
                'updated_at' => $s->medicalRecord->updated_at->diffForHumans(),
            ]);

        return Inertia::render('Medical/Index', [
            'students' => $students,
            'totalEnrolled' => Student::whereIn('branch_id', $branchIds)->enrolled()->count(),
            'canManage' => $user->can('medical-records-manage'),
        ]);
    }

    /** Same shape as DisciplineIncidentController::studentSearch() -- a quick way to reach "add this
     * student's medical info" without first opening their profile. Gated on medical-records-manage
     * directly since that's its only purpose. */
    public function studentSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('medical-records-manage'), 403);

        $term = $request->string('q')->toString();
        abort_unless(strlen($term) >= 2, 422);

        $branchIds = $user->isOrgWide() ? $user->school->branches()->pluck('id') : collect([$user->branch_id]);

        $students = Student::whereIn('branch_id', $branchIds)
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$term}%")->orWhere('father_name', 'like', "%{$term}%"))
            ->whereIn('status', Student::ENROLLED_STATUSES)
            ->with('currentEnrollment.section.grade')
            ->limit(10)->get()
            ->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->fullName(),
                'detail' => $s->currentEnrollment ? "{$s->currentEnrollment->section->grade->name} {$s->currentEnrollment->section->name}" : null,
            ]);

        return response()->json($students);
    }

    public function show(Request $request, Student $student): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $this->assertInScope($user, $student);

        $record = $student->medicalRecord;

        return Inertia::render('Students/Medical', [
            'student' => ['id' => $student->id, 'name' => $student->fullName(), 'student_id_number' => $student->student_id_number],
            'record' => [
                'blood_type' => $record?->blood_type,
                'allergies' => $record?->allergies,
                'conditions' => $record?->conditions,
                'medications' => $record?->medications,
                'physician_name' => $record?->physician_name,
                'physician_phone' => $record ? PhoneNumber::display($record->physician_phone) : null,
                'notes' => $record?->notes,
                'updated_by' => $record?->updatedBy?->name,
                'updated_at' => $record?->updated_at?->diffForHumans(),
            ],
            'canManage' => $user->can('medical-records-manage'),
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $user = $request->user();
        $user->can('medical-records-manage') || abort(403);
        $this->assertInScope($user, $student);

        $validated = $request->validate([
            'blood_type' => ['nullable', 'string', 'max:10'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'medications' => ['nullable', 'string', 'max:2000'],
            'physician_name' => ['nullable', 'string', 'max:150'],
            'physician_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $existed = $student->medicalRecord()->exists();
        $record = StudentMedicalRecord::updateOrCreate(
            ['student_id' => $student->id],
            $validated + ['updated_by' => $user->id],
        );

        // No field values in before/after -- see the class docblock on StudentMedicalRecord for why.
        AuditLog::record($user, $existed ? 'student_medical_record.updated' : 'student_medical_record.created', $record);

        return back()->with('success', 'Medical record saved.');
    }
}
