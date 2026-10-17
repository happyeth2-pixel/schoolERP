<?php

namespace App\Http\Controllers;

use App\Services\SectionCapacity;
use App\Services\SectionRecommendation;
use App\Services\StudentServices;
use App\Support\SafeUpload;
use App\Rules\SafeUploadRule;
use App\Models\AuditLog;
use App\Exports\SimpleArrayExport;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\OptionalService;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\AccountOnboarding;
use App\Support\SchoolCalendar;
use App\Http\Controllers\Concerns\PaginatesLists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Rules\PhoneNumberRule;
use App\Support\PhoneNumber;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class StudentController extends Controller
{
    use PaginatesLists;

    /**
     * "Quick find," resolved by the database: typing used to filter a copy of every student already sent to the
     * browser, which is exactly the thing that doesn't scale once a school has hundreds of students. Each typed
     * word must match the name, student ID, class, mother's name or a guardian's name (same as before, just as a
     * query instead of a client-side token scan); a phone number. however it was typed, matches a guardian's or
     * the emergency contact's number too -- still never sent to the browser just to be searched (see contacts()).
     */
    private function applyStudentSearch(Builder $query, string $term): void
    {
        $tokens = preg_split('/\s+/', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY);
        $phoneKey = PhoneNumber::searchKey($term);
        $looksLikePhone = preg_match('/^[\d\s+()-]+$/', $term) === 1 && strlen($phoneKey) >= 4;

        $query->where(function ($outer) use ($tokens, $phoneKey, $looksLikePhone) {
            if ($tokens !== []) {
                $outer->where(function ($text) use ($tokens) {
                    foreach ($tokens as $token) {
                        $like = '%'.$token.'%';
                        $text->where(function ($t) use ($like) {
                            $t->where('first_name', 'like', $like)
                                ->orWhere('father_name', 'like', $like)
                                ->orWhere('grandfather_name', 'like', $like)
                                ->orWhere('mother_name', 'like', $like)
                                ->orWhere('student_id_number', 'like', $like)
                                ->orWhereHas('currentEnrollment.section.grade', fn ($g) => $g->where('name', 'like', $like))
                                ->orWhereHas('currentEnrollment.section', fn ($s) => $s->where('name', 'like', $like))
                                ->orWhereHas('guardians', fn ($gu) => $gu->where('name', 'like', $like));
                        });
                    }
                });
            }

            if ($looksLikePhone) {
                $outer->orWhere(function ($p) use ($phoneKey) {
                    $p->where('emergency_contact', 'like', "%{$phoneKey}%")
                        ->orWhereHas('guardians', fn ($g) => $g->where('phone', 'like', "%{$phoneKey}%"));
                });
            }
        });
    }

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

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $this->applyStudentSearch($query, $term);
            $query->orderBy('first_name');
        } else {
            // nothing typed yet: the most recently enrolled first, same idea as before, now a real page instead
            // of a client-side slice of everyone
            $query->orderByDesc('id');
        }

        // Right after registering, the list opens on a confirmation for that student (see store()).
        $justRegistered = null;
        if ($newId = $request->session()->get('registered_student')) {
            $new = Student::where('school_id', $user->school_id)
                ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
                ->with(['currentEnrollment.section.grade', 'guardians'])
                ->find($newId);
            if ($new) {
                $justRegistered = [
                    'id' => $new->id,
                    'name' => $new->fullName(),
                    'student_id_number' => $new->student_id_number,
                    'section' => $new->currentEnrollment
                        ? "{$new->currentEnrollment->section->grade->name} {$new->currentEnrollment->section->name}"
                        : null,
                    'guardians' => $new->guardians->pluck('name'),
                ];
            }
        }

        $paginator = $query->paginate($this->perPage($request, default: 20))->withQueryString();

        $students = $paginator->getCollection()->map(fn (Student $s) => [
            'id' => $s->id,
            'student_id_number' => $s->student_id_number,
            'name' => $s->fullName(),
            'gender' => $s->gender,
            'age' => $s->age(),
            'status' => $s->status,
            'branch' => $s->branch->name,
            'section' => $s->currentEnrollment
                ? "{$s->currentEnrollment->section->grade->name} {$s->currentEnrollment->section->name}"
                : 'Unassigned',
            'grade' => $s->currentEnrollment?->section->grade->name,
            'section_name' => $s->currentEnrollment?->section->name,
            'guardians' => $s->guardians->pluck('name'),
            'mother_name' => $s->mother_name,
        ])->values();

        return Inertia::render('Students/Index', [
            'students' => $students,
            'studentsMeta' => $this->paginationMeta($paginator),
            'q' => $term,
            'justRegistered' => $justRegistered,
            'canManage' => $user->can('manage-students'),
        ]);
    }

    /**
     * The parent / guardian names and phone numbers of the few students a search is showing (JSON, at most 20 ids).
     * The search page never receives everyone's numbers: it asks for exactly the results on screen, and only people who
     * manage students get an answer, inside their own school and branch (an id outside that is simply left out).
     */
    public function contacts(Request $request)
    {
        $user = $request->user();
        $user->can('manage-students') || abort(403);

        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) $id)->filter()->unique()->take(20)->values();

        $students = Student::query()
            ->where('school_id', $user->school_id)
            ->whereIn('id', $ids)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->with('guardians')
            ->get();

        return response()->json(['contacts' => $students->mapWithKeys(fn (Student $s) => [
            $s->id => $s->guardians->map(fn ($g) => [
                'name' => $g->name,
                'relationship' => Student::relationshipLabel($g->pivot->relationship),
                'phone' => $g->phone ? PhoneNumber::display($g->phone) : null,
            ])->values(),
        ])]);
    }

    public function export(Request $request)
    {
        $request->user()->canViewOrManage('manage-students') || abort(403);
        $user = $request->user();

        $query = Student::query()
            ->where('school_id', $user->school_id)
            ->with(['branch', 'currentEnrollment.section.grade', 'guardians', 'optionalServices']);

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
            ['Student List — '.SchoolCalendar::date(SchoolCalendar::today(), $user)],
            ['Student ID', 'Name', 'Gender', 'Status', 'Branch', 'Section', 'Guardian(s)', 'Fee Status', 'Optional Services'],
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
                $s->optionalServices->pluck('name')->implode(', ') ?: 'None',
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
            ->with(['grade', 'classTeacher:id,name']);

        if (! $user->isOrgWide()) {
            $sectionsQuery->where('branch_id', $user->branch_id);
        }

        return Inertia::render('Students/Create', [
            'guardianRelationships' => Student::GUARDIAN_RELATIONSHIPS,
            'sections' => SectionRecommendation::annotate($sectionsQuery->get()->map(fn (Section $s) => [
                'id' => $s->id,
                'label' => "{$s->grade->name} {$s->name}",
                'grade' => $s->grade->name,
                'grade_order' => $s->grade->sort_order,
                'name' => $s->name,
                'teacher' => $s->classTeacher?->name,
                'branch_id' => $s->branch_id,
            ] + SectionCapacity::describe($s))),
            'canOverrideCapacity' => $user->can('override-section-capacity'),
            'canManageServices' => $user->can('manage-invoices'),
            'services' => $user->can('manage-invoices') ? $this->servicesForCreate($user) : [],
        ]);
    }

    /** Opt-in services of the current year in every branch this user may register students into. */
    private function servicesForCreate(User $user): array
    {
        $year = $user->school->academicYears()->where('is_current', true)->first();
        if (! $year) {
            return [];
        }

        $query = OptionalService::where('academic_year_id', $year->id)
            ->whereIn('branch_id', $user->school->branches()->pluck('id'));
        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query->orderBy('name')->get()->map(fn (OptionalService $s) => [
            'id' => $s->id,
            'branch_id' => $s->branch_id,
            'name' => $s->name,
            'category' => $s->category,
            'amount' => $s->amount,
            'frequency' => $s->frequency,
        ])->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'grandfather_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['required', 'in:male,female'],
            'date_of_birth' => $this->dateOfBirthRules(),
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:50', new PhoneNumberRule],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'enrollment_date' => ['required', 'date', 'after_or_equal:date_of_birth'],
            'section_id' => ['required', 'integer'],
            'override_capacity' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'guardian_mode' => ['required', 'in:existing,new,none'],
            'guardian_email' => ['required_if:guardian_mode,existing,new', 'nullable', 'email'],
            'guardian_name' => ['required_if:guardian_mode,new', 'nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:50', new PhoneNumberRule],
            'guardian_relationship' => ['required_if:guardian_mode,existing,new', 'nullable', Rule::in(array_keys(Student::GUARDIAN_RELATIONSHIPS))],
            'service_ids' => ['nullable', 'array', 'max:20'],
            'service_ids.*' => ['integer', 'distinct'],
            'photo' => ['nullable', 'file', 'max:2048', new SafeUploadRule(['jpg', 'png'])],
        ]);

        $section = Section::whereKey($validated['section_id'])->firstOrFail();
        $branchIds = $user->school->branches()->pluck('id');
        abort_unless($branchIds->contains($section->branch_id), 403);
        if (! $user->isOrgWide()) {
            abort_unless($section->branch_id === $user->branch_id, 403);
        }

        // Opt-in services chosen at registration: same permission, branch and one-transport rules as on the profile.
        $services = collect();
        if (! empty($validated['service_ids'])) {
            $user->can('manage-invoices') || abort(403);
            $services = OptionalService::whereIn('id', $validated['service_ids'])
                ->where('branch_id', $section->branch_id)
                ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
                ->get();
            if ($services->count() !== count($validated['service_ids'])) {
                throw ValidationException::withMessages(['service_ids' => 'One of the chosen services is not available for the chosen class.']);
            }
            if ($services->where('category', 'transport')->count() > 1) {
                throw ValidationException::withMessages(['service_ids' => 'A student can use only one transport route.']);
            }
        }

        $student = DB::transaction(function () use ($validated, $section, $user, $services) {
            // a seat must be free (or knowingly overridden) BEFORE the student is created
            SectionCapacity::claim($section, 1, $user, (bool) ($validated['override_capacity'] ?? false), $validated['override_reason'] ?? null);

            $student = Student::create([
                'school_id' => $user->school_id,
                'branch_id' => $section->branch_id,
                'student_id_number' => $this->nextStudentIdNumber($user->school_id, $validated['enrollment_date']),
                'first_name' => $validated['first_name'],
                'father_name' => $validated['father_name'] ?? null,
                'grandfather_name' => $validated['grandfather_name'] ?? null,
                'mother_name' => $validated['mother_name'] ?? null,
                'gender' => $validated['gender'],
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'address' => $validated['address'] ?? null,
                'emergency_contact' => $validated['emergency_contact'] ?? null,
                'previous_school' => $validated['previous_school'] ?? null,
                'enrollment_date' => $validated['enrollment_date'],
                'status' => 'active',
                'id_card_token' => Student::newCardToken(),
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
                AuditLog::record($user, 'student.guardian_linked', $student, null, ['guardian' => $guardian->name, 'relationship' => $validated['guardian_relationship']]);
            }

            foreach ($services as $service) {
                StudentServices::enroll($user, $student, $service, null, false);
            }

            return $student;
        });

        // The photo is optional and never holds up a registration: the record is already saved, so a photo that
        // cannot be stored is reported and can be added again from the profile.
        $photoProblem = null;
        if ($request->hasFile('photo')) {
            try {
                $this->storePhoto($student, $request->file('photo'));
            } catch (\RuntimeException) {
                $photoProblem = 'The student was registered, but the photo could not be saved. Open the profile to add it again.';
            }
        }

        $redirect = redirect()->route('students.index')
            ->with('success', "{$student->fullName()} enrolled as {$student->student_id_number}.")
            ->with('registered_student', $student->id);

        return $photoProblem ? $redirect->with('warning', $photoProblem) : $redirect;
    }

    public function edit(Request $request, Student $student): Response
    {
        $user = $request->user();
        $user->canViewOrManage('manage-students') || abort(403);
        $this->assertInScope($user, $student);

        $student->load(['currentEnrollment.section.grade', 'guardians', 'optionalServices']);

        $currentYear = $user->school->academicYears()->where('is_current', true)->first();
        $enrolledById = $student->optionalServices->keyBy('id');
        $availableServices = $currentYear
            ? OptionalService::where('branch_id', $student->branch_id)
                ->where('academic_year_id', $currentYear->id)
                ->get()
                ->map(fn (OptionalService $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'category' => $s->category,
                    'amount' => $s->amount,
                    'custom_amount' => $enrolledById->get($s->id)?->pivot->custom_amount,
                    'frequency' => $s->frequency,
                    'enrolled' => $enrolledById->has($s->id),
                    'enrolled_on' => $enrolledById->get($s->id)?->pivot->enrolled_on,
                ])
            : collect();

        $transferSections = $currentYear
            ? SectionRecommendation::annotate(Section::where('branch_id', $student->branch_id)
                ->where('academic_year_id', $currentYear->id)
                ->where('id', '!=', $student->currentEnrollment?->section_id)
                ->with(['grade', 'classTeacher:id,name'])
                ->get()
                ->map(fn (Section $s) => ['id' => $s->id, 'label' => "{$s->grade->name} {$s->name}", 'grade' => $s->grade->name, 'grade_order' => $s->grade->sort_order, 'name' => $s->name, 'teacher' => $s->classTeacher?->name] + SectionCapacity::describe($s)))
            : collect();

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
                'age' => $student->age(),
                'address' => $student->address,
                'emergency_contact' => PhoneNumber::display($student->emergency_contact),
                'previous_school' => $student->previous_school,
                'status' => $student->status,
                'withdrawn_on' => $student->withdrawn_on?->toDateString(),
                'withdrawal_reason' => $student->withdrawal_reason,
                'photo_url' => $student->photo_path ? Storage::disk('public')->url($student->photo_path) : null,
                'section' => $student->currentEnrollment
                    ? "{$student->currentEnrollment->section->grade->name} {$student->currentEnrollment->section->name}"
                    : null,
                'guardians' => $student->guardians->map(fn ($g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'email' => $g->email,
                    // a parent's number is what a front desk needs to call them; only shown to people who manage students
                    'phone' => $user->can('manage-students') && $g->phone ? PhoneNumber::display($g->phone) : null,
                    'relationship' => $g->pivot->relationship,
                    'relationship_label' => Student::relationshipLabel($g->pivot->relationship),
                ]),
            ],
            'guardianRelationships' => Student::GUARDIAN_RELATIONSHIPS,
            'availableServices' => $availableServices,
            'transferSections' => $transferSections,
            'canOverrideCapacity' => $user->can('override-section-capacity'),
            'canManage' => $user->can('manage-students'),
            'minWithdrawDate' => $student->enrollment_date->toDateString(),
            'canManageServices' => $user->can('manage-invoices'),
            // Capability flags only -- never the medical data itself. See StudentMedicalRecordController.
            'canViewMedical' => $user->can('medical-records-view') || $user->can('medical-records-manage') || $user->hasRole('auditor'),
            // Capability flag only -- never the incident content itself. See DisciplineIncidentController.
            'canViewDiscipline' => $user->can('discipline-view') || $user->can('discipline-manage') || $user->hasRole('auditor'),
        ]);
    }

    /**
     * The only way to withdraw a student. Needs an effective date (today
     * or earlier, not before they enrolled) and a reason; see
     * Student::withdraw() for what that closes, waives and preserves.
     */
    public function withdraw(Request $request, Student $student): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-students') || abort(403);
        $this->assertInScope($user, $student);

        if (! in_array($student->status, Student::ENROLLED_STATUSES, true)) {
            return back()->withErrors(['effective_date' => 'This student is not currently enrolled.']);
        }

        $validated = $request->validate([
            'effective_date' => ['required', 'date', 'before_or_equal:'.SchoolCalendar::today()->toDateString(), 'after_or_equal:'.$student->enrollment_date->toDateString()],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $result = $student->withdraw(Carbon::parse($validated['effective_date']), $validated['reason'], $user);

        $note = "{$student->fullName()} withdrawn effective {$validated['effective_date']}. "
            ."{$result['waived']} later unpaid invoice(s) waived";
        if ($result['kept_with_payments'] > 0) {
            $note .= ", {$result['kept_with_payments']} later invoice(s) left as-is because they already have payments — review them";
        }

        return redirect()->route('students.index')->with('success', $note.'.');
    }

    /**
     * Moves a single student to a different section mid-year --
     * distinct from the bulk, year-end Promote Students tool. The old
     * enrollment is marked "transferred" (not "completed", which
     * stays reserved for a normal end-of-year promotion) so the two
     * kinds of section change are always distinguishable in history.
     */
    public function transfer(Request $request, Student $student): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-students') || abort(403);
        $this->assertInScope($user, $student);

        $validated = $request->validate([
            'to_section_id' => ['required', 'integer'],
            'override_capacity' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $toSection = Section::whereKey($validated['to_section_id'])->firstOrFail();
        abort_unless($toSection->branch_id === $student->branch_id, 422);

        if (! in_array($student->status, Student::ENROLLED_STATUSES, true)) {
            return back()->withErrors(['to_section_id' => 'Set the student back to active before placing them in a section.']);
        }

        $current = $student->currentEnrollment;
        if ($current && $current->section_id === $toSection->id) {
            return back()->withErrors(['to_section_id' => 'Student is already in that section.']);
        }

        $fromLabel = $current?->section ? trim($current->section->grade->name.' '.$current->section->name) : null;

        // The enrollment rows change as part of ONE transfer, so ONE entry describes it.
        AuditLog::suspended(fn () => DB::transaction(function () use ($student, $current, $toSection, $user, $validated) {
            SectionCapacity::claim($toSection, 1, $user, (bool) ($validated['override_capacity'] ?? false), $validated['override_reason'] ?? null, 'to_section_id');

            if ($current) {
                $current->update(['status' => 'transferred']);
            }

            $existing = Enrollment::where('student_id', $student->id)
                ->where('section_id', $toSection->id)
                ->first();

            if ($existing) {
                $existing->update(['status' => 'active', 'enrolled_on' => SchoolCalendar::today()->toDateString()]);
            } else {
                Enrollment::create([
                    'student_id' => $student->id,
                    'section_id' => $toSection->id,
                    'enrolled_on' => SchoolCalendar::today()->toDateString(),
                    'status' => 'active',
                ]);
            }
        }));

        AuditLog::record($user, 'student.transferred', $student, ['section' => $fromLabel], ['section' => trim($toSection->grade->name.' '.$toSection->name)]);

        return back()->with('success', "{$student->fullName()} transferred to {$toSection->grade->name} {$toSection->name}.");
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $this->assertInScope($request->user(), $student);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'grandfather_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['required', 'in:male,female'],
            'date_of_birth' => $this->dateOfBirthRules(),
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:50', new PhoneNumberRule],
            'previous_school' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,transferred,graduated,withdrawn,suspended,expelled,inactive'],
        ]);

        $status = $validated['status'];
        unset($validated['status']);

        if ($status === 'withdrawn' && $student->status !== 'withdrawn') {
            return back()->withErrors(['status' => 'Use the Withdraw button — it records the date and reason and stops billing.']);
        }

        DB::transaction(function () use ($student, $status, $request, $validated) {
            $student->update($validated);
            if ($status !== $student->status) {
                // going back to "active" re-opens the enrolment they left, which takes a seat again
                if ($status === 'active') {
                    $reopens = $student->reopenableEnrollment();
                    if ($reopens) {
                        SectionCapacity::claim($reopens->section, 1, $request->user(), $request->boolean('override_capacity'), $request->input('override_reason'), 'status');
                    }
                }

                $student->changeStatus($status);
            }
        });

        return back()->with('success', 'Student record updated.');
    }

    public function uploadPhoto(Request $request, Student $student): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $this->assertInScope($request->user(), $student);

        $validated = $request->validate([
            'photo' => ['required', 'file', 'max:2048', new SafeUploadRule(['jpg', 'png'])],
        ]);

        try {
            $this->storePhoto($student, $validated['photo']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['photo' => 'This photo could not be saved. Please choose another one.']);
        }

        return back()->with('success', 'Photo updated.');
    }

    /** Takes the student's photo away (the record and everything else stay). Same permission and branch as adding one. */
    public function removePhoto(Request $request, Student $student): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $this->assertInScope($request->user(), $student);

        if (! $student->photo_path) {
            return back()->with('success', 'There was no photo to remove.');
        }

        $old = $student->photo_path;
        $student->update(['photo_path' => null]);
        Storage::disk('public')->delete($old);

        return back()->with('success', 'Photo removed.');
    }

    /**
     * The one place a student photo is stored: decoded, shrunk and re-encoded under a random name (the same behaviour
     * as before). The new file is written first and the old one deleted only once the record points at the new one,
     * so a failure never leaves a student without a photo they had.
     */
    private function storePhoto(Student $student, \Illuminate\Http\UploadedFile $file): void
    {
        $old = $student->photo_path;
        $path = SafeUpload::storePublicImage($file, 'student-photos', 800);
        $student->update(['photo_path' => $path]);

        if ($old) {
            Storage::disk('public')->delete($old);
        }
    }

    public function addGuardian(Request $request, Student $student): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $this->assertInScope($request->user(), $student);

        $validated = $request->validate([
            'guardian_mode' => ['required', 'in:existing,new'],
            'guardian_email' => ['required', 'email'],
            'guardian_name' => ['required_if:guardian_mode,new', 'nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:50', new PhoneNumberRule],
            'guardian_relationship' => ['required', Rule::in(array_keys(Student::GUARDIAN_RELATIONSHIPS))],
        ]);

        $guardian = $this->resolveGuardian($request->user(), $validated);

        if ($student->guardians()->where('parent_id', $guardian->id)->exists()) {
            return back()->withErrors(['guardian_email' => 'This guardian is already linked to this student.']);
        }

        $student->guardians()->attach($guardian->id, ['relationship' => $validated['guardian_relationship']]);
        AuditLog::record($request->user(), 'student.guardian_linked', $student, null, ['guardian' => $guardian->name, 'relationship' => $validated['guardian_relationship']]);

        return back()->with('success', "{$guardian->name} linked as ".strtolower(Student::relationshipLabel($validated['guardian_relationship'])).'.');
    }

    public function removeGuardian(Request $request, Student $student, User $guardian): RedirectResponse
    {
        $request->user()->can('manage-students') || abort(403);
        $this->assertInScope($request->user(), $student);

        $student->guardians()->detach($guardian->id);
        AuditLog::record($request->user(), 'student.guardian_unlinked', $student, ['guardian' => $guardian->name], null);

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

        // Families do share a number (so it is not unique), but the registrar should
        // know when a "new" guardian's phone already belongs to somebody else.
        if ($guardian->wasRecentlyCreated && $guardian->phone) {
            $other = User::where('school_id', $actor->school_id)->where('phone', $guardian->phone)->where('id', '!=', $guardian->id)->first();
            if ($other) {
                session()->flash('warning', "The phone number {$other->phone} is already used by {$other->name} ({$other->email}). If this is the same person, link the existing parent instead of creating a second account.");
            }
        }

        // A brand-new guardian account gets a "set your password" link instead of a
        // password nobody knows; the registrar sees the link once to hand over.
        if ($guardian->wasRecentlyCreated) {
            AccountOnboarding::invite($guardian, $actor);
        }

        return $guardian;
    }

    /** Not in the future, and not implausibly old for a school student. */
    private function dateOfBirthRules(): array
    {
        return ['nullable', 'date', 'before_or_equal:'.SchoolCalendar::today()->toDateString(), 'after:'.SchoolCalendar::today()->subYears(40)->toDateString()];
    }

    private function assertInScope($user, Student $student): void
    {
        abort_unless($student->school_id === $user->school_id, 403);

        if (! $user->isOrgWide()) {
            abort_unless($student->branch_id === $user->branch_id, 403);
        }
    }

    /**
     * STU-{year}-{6 digits}. The counter is the locked number_sequences row (the same mechanism as receipt numbers), seeded
     * with the highest number already issued, so a gap in the existing numbers (an import, a hand-typed number) can never
     * make the next registration collide with one that exists. Called inside store()'s transaction.
     */
    private function nextStudentIdNumber(int $schoolId, string $enrollmentDate): string
    {
        $year = (int) date('Y', strtotime($enrollmentDate));
        $prefix = "STU-{$year}-";

        $seed = fn () => Student::where('school_id', $schoolId)
            ->where('student_id_number', 'like', "{$prefix}%")
            ->pluck('student_id_number')
            ->filter(fn ($number) => preg_match('/^'.preg_quote($prefix, '/').'\d+$/', $number))
            ->map(fn ($number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        // skip any number somebody put in by hand or by import after the counter was started
        do {
            $number = $prefix.str_pad((string) \App\Models\NumberSequence::next($schoolId, 'student', $year, $seed), 6, '0', STR_PAD_LEFT);
        } while (Student::where('student_id_number', $number)->exists());

        return $number;
    }
}
