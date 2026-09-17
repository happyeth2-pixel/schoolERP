<?php

namespace App\Http\Controllers;

use App\Exports\SimpleArrayExport;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->canViewOrManage('manage-students') || abort(403);
        $user = $request->user();

        $query = Student::query()
            ->where('school_id', $user->school_id)
            ->with(['branch', 'currentEnrollment.section.grade', 'guardians']);

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        $students = $query->orderBy('first_name')->get()->map(fn (Student $s) => [
            'id' => $s->id,
            'student_id_number' => $s->student_id_number,
            'name' => $s->fullName(),
            'gender' => $s->gender,
            'status' => $s->status,
            'branch' => $s->branch->name,
            'section' => $s->currentEnrollment
                ? "{$s->currentEnrollment->section->grade->name} {$s->currentEnrollment->section->name}"
                : 'Unassigned',
            'guardians' => $s->guardians->pluck('name'),
        ]);

        return Inertia::render('Students/Index', ['students' => $students]);
    }

    public function export(Request $request)
    {
        $request->user()->canViewOrManage('manage-students') || abort(403);
        $user = $request->user();

        $query = Student::query()
            ->where('school_id', $user->school_id)
            ->with(['branch', 'currentEnrollment.section.grade', 'guardians']);

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        $students = $query->orderBy('first_name')->get();

        // One query for every current-year invoice rather than N+1 --
        // matched back to each student by student_id below.
        $invoicesByStudent = Invoice::whereIn('student_id', $students->pluck('id'))
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->with('items', 'payments')
            ->get()
            ->keyBy('student_id');

        $rows = [
            [$user->school->name],
            ['Student List — '.now()->format('M j, Y')],
            ['Student ID', 'Name', 'Gender', 'Status', 'Branch', 'Section', 'Guardian(s)', 'Fee Status'],
        ];

        foreach ($students as $s) {
            $invoice = $invoicesByStudent->get($s->id);
            $rows[] = [
                $s->student_id_number,
                $s->fullName(),
                $s->gender,
                $s->status,
                $s->branch->name,
                $s->currentEnrollment ? "{$s->currentEnrollment->section->grade->name} {$s->currentEnrollment->section->name}" : 'Unassigned',
                $s->guardians->pluck('name')->implode(', '),
                $invoice ? str_replace('_', ' ', $invoice->statusLabel()) : 'No invoice',
            ];
        }

        return Excel::download(new SimpleArrayExport($rows, 'Students'), 'students.xlsx');
    }

    public function create(Request $request): Response
    {
        $request->user()->can('manage-students') || abort(403);
        $user = $request->user();

        $sectionsQuery = Section::query()
            ->whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->with('grade');

        if (! $user->isOrgWide()) {
            $sectionsQuery->where('branch_id', $user->branch_id);
        }

        return Inertia::render('Students/Create', [
            'sections' => $sectionsQuery->get()->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->grade->name} {$s->name}",
                'branch_id' => $s->branch_id,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'father_name' => ['required', 'string', 'max:255'],
            'grandfather_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['required', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'enrollment_date' => ['required', 'date'],
            'section_id' => ['required', 'integer'],
            'guardian_mode' => ['required', 'in:existing,new,none'],
            'guardian_email' => ['required_if:guardian_mode,existing,new', 'nullable', 'email'],
            'guardian_name' => ['required_if:guardian_mode,new', 'nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:50'],
            'guardian_relationship' => ['required_if:guardian_mode,existing,new', 'nullable', 'in:father,mother,guardian'],
        ]);

        $section = Section::whereKey($validated['section_id'])->firstOrFail();
        $branchIds = $user->school->branches()->pluck('id');
        abort_unless($branchIds->contains($section->branch_id), 403);
        if (! $user->isOrgWide()) {
            abort_unless($section->branch_id === $user->branch_id, 403);
        }

        $student = DB::transaction(function () use ($validated, $section, $user) {
            $student = Student::create([
                'school_id' => $user->school_id,
                'branch_id' => $section->branch_id,
                'student_id_number' => $this->nextStudentIdNumber($user->school_id, $validated['enrollment_date']),
                'first_name' => $validated['first_name'],
                'father_name' => $validated['father_name'],
                'grandfather_name' => $validated['grandfather_name'] ?? null,
                'mother_name' => $validated['mother_name'] ?? null,
                'gender' => $validated['gender'],
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'address' => $validated['address'] ?? null,
                'emergency_contact' => $validated['emergency_contact'] ?? null,
                'previous_school' => $validated['previous_school'] ?? null,
                'enrollment_date' => $validated['enrollment_date'],
                'status' => 'active',
                'id_card_token' => Str::random(40),
            ]);

            Enrollment::create([
                'student_id' => $student->id,
                'section_id' => $section->id,
                'enrolled_on' => $validated['enrollment_date'],
                'status' => 'active',
            ]);

            if ($validated['guardian_mode'] !== 'none') {
                $guardian = $this->resolveGuardian($user, $validated);
                $student->guardians()->attach($guardian->id, ['relationship' => $validated['guardian_relationship']]);
            }

            return $student;
        });

        return redirect()->route('students.index')->with('success', "{$student->fullName()} enrolled as {$student->student_id_number}.");
    }

    public function edit(Request $request, Student $student): Response
    {
        $request->user()->canViewOrManage('manage-students') || abort(403);
        $this->assertInScope($request->user(), $student);

        $student->load(['currentEnrollment.section.grade', 'guardians']);

        return Inertia::render('Students/Edit', [
            'student' => [
                'id' => $student->id,
                'student_id_number' => $student->student_id_number,
                'first_name' => $student->first_name,
                'father_name' => $student->father_name,
                'grandfather_name' => $student->grandfather_name,
                'mother_name' => $student->mother_name,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->format('Y-m-d'),
                'address' => $student->address,
                'emergency_contact' => $student->emergency_contact,
                'previous_school' => $student->previous_school,
                'status' => $student->status,
                'photo_url' => $student->photo_path ? Storage::disk('public')->url($student->photo_path) : null,
                'section' => $student->currentEnrollment
                    ? "{$student->currentEnrollment->section->grade->name} {$student->currentEnrollment->section->name}"
                    : null,
                'guardians' => $student->guardians->map(fn ($g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'email' => $g->email,
                    'relationship' => $g->pivot->relationship,
                ]),
            ],
            'canManage' => $request->user()->can('manage-students'),
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $this->assertInScope($request->user(), $student);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'father_name' => ['required', 'string', 'max:255'],
            'grandfather_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['required', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,transferred,graduated,withdrawn,suspended,expelled,inactive'],
        ]);

        $student->update($validated);

        return back()->with('success', 'Student record updated.');
    }

    public function uploadPhoto(Request $request, Student $student): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $this->assertInScope($request->user(), $student);

        $validated = $request->validate([
            'photo' => ['required', 'image', 'max:2048'],
        ]);

        if ($student->photo_path) {
            Storage::disk('public')->delete($student->photo_path);
        }

        $path = $validated['photo']->store('student-photos', 'public');
        $student->update(['photo_path' => $path]);

        return back()->with('success', 'Photo updated.');
    }

    public function addGuardian(Request $request, Student $student): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $this->assertInScope($request->user(), $student);

        $validated = $request->validate([
            'guardian_mode' => ['required', 'in:existing,new'],
            'guardian_email' => ['required', 'email'],
            'guardian_name' => ['required_if:guardian_mode,new', 'nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:50'],
            'guardian_relationship' => ['required', 'in:father,mother,guardian'],
        ]);

        $guardian = $this->resolveGuardian($request->user(), $validated);

        if ($student->guardians()->where('parent_id', $guardian->id)->exists()) {
            return back()->withErrors(['guardian_email' => 'This guardian is already linked to this student.']);
        }

        $student->guardians()->attach($guardian->id, ['relationship' => $validated['guardian_relationship']]);

        return back()->with('success', "{$guardian->name} linked as {$validated['guardian_relationship']}.");
    }

    public function removeGuardian(Request $request, Student $student, User $guardian): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $this->assertInScope($request->user(), $student);

        $student->guardians()->detach($guardian->id);

        return back()->with('success', 'Guardian unlinked.');
    }

    private function resolveGuardian($actor, array $data): User
    {
        if ($data['guardian_mode'] === 'existing') {
            $guardian = User::where('school_id', $actor->school_id)
                ->where('email', $data['guardian_email'])
                ->whereHas('roles', fn ($q) => $q->where('name', 'parent'))
                ->first();

            abort_unless($guardian, 422, 'No parent account found with that email.');

            return $guardian;
        }

        $guardian = User::firstOrCreate(
            ['email' => $data['guardian_email']],
            [
                'school_id' => $actor->school_id,
                'branch_id' => null,
                'name' => $data['guardian_name'],
                'phone' => $data['guardian_phone'] ?? null,
                'password' => Hash::make(str()->random(24)),
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        if (! $guardian->hasRole('parent')) {
            $guardian->assignTier('parent');
        }

        return $guardian;
    }

    private function assertInScope($user, Student $student): void
    {
        abort_unless($student->school_id === $user->school_id, 403);

        if (! $user->isOrgWide()) {
            abort_unless($student->branch_id === $user->branch_id, 403);
        }
    }

    private function nextStudentIdNumber(int $schoolId, string $enrollmentDate): string
    {
        $year = date('Y', strtotime($enrollmentDate));
        $prefix = "STU-{$year}-";

        $count = Student::where('school_id', $schoolId)
            ->where('student_id_number', 'like', "{$prefix}%")
            ->count();

        return $prefix.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
