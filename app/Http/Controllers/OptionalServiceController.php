<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\OptionalService;
use App\Models\Period;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentServices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OptionalServiceController extends Controller
{
    private const CUSTOM_AMOUNT_RULES = ['nullable', 'numeric', 'min:0', 'max:99999999.99'];

    public function index(Request $request): Response
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $user = $request->user();

        $query = OptionalService::whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->with(['branch', 'academicYear', 'period', 'teacher:id,name']);

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        return Inertia::render('OptionalServices/Index', [
            'optionalServices' => $query->orderBy('name')->get()->map(fn (OptionalService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'category' => $s->category,
                'amount' => $s->amount,
                'frequency' => $s->frequency,
                'branch' => $s->branch->name,
                'academic_year' => $s->academicYear->name,
                'enrolled_count' => $s->students()->count(),
                'period' => $s->period?->label(),
                'teacher' => $s->teacher?->name,
                'days_of_week' => $s->days_of_week ?? [],
            ]),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'academicYears' => $user->school->academicYears()->orderByDesc('start_date')->get(['id', 'name', 'is_current']),
        ]);
    }

    /** "Add a service" as three short steps: where it applies, what it is, what it costs. */
    public function create(Request $request): Response
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $user = $request->user();

        return Inertia::render('OptionalServices/Wizard', [
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'academicYears' => $user->school->academicYears()->orderByDesc('start_date')->get(['id', 'name', 'is_current']),
            'existing' => OptionalService::whereIn('branch_id', $user->school->branches()->pluck('id'))
                ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
                ->get(['id', 'branch_id', 'academic_year_id', 'name', 'category']),
            // Only teaching periods are offered -- a break/lunch period can never host a programme
            // (OptionalServiceController::resolveSchedule()).
            'periods' => Period::where('school_id', $user->school_id)->where('type', 'teaching')->orderBy('period_number')
                ->get()->map(fn (Period $p) => ['id' => $p->id, 'label' => $p->label()]),
            'teachers' => User::where('school_id', $user->school_id)->where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->where('name', 'teacher'))
                ->orderBy('name')->get(['id', 'name', 'branch_id']),
            'saved' => session('service_saved'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'academic_year_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['sometimes', 'in:'.implode(',', OptionalService::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'in:annual,termly,monthly'],
        ] + self::scheduleRules());

        $this->assertBranchInScope($user, (int) $validated['branch_id']);
        abort_unless($user->school->academicYears()->whereKey($validated['academic_year_id'])->exists(), 403);
        $this->resolveSchedule($user, $validated, (int) $validated['branch_id']);

        $service = OptionalService::create($validated);

        return back()->with('success', 'Optional service added.')->with('service_saved', [
            'id' => $service->id,
            'name' => $service->name,
            'category' => $service->category,
            'amount' => $service->amount,
            'frequency' => $service->frequency,
            'branch' => $service->branch->name,
            'academic_year' => $service->academicYear->name,
        ]);
    }

    public function update(Request $request, OptionalService $optionalService): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($request->user(), $optionalService->branch_id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['sometimes', 'in:'.implode(',', OptionalService::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'in:annual,termly,monthly'],
        ] + self::scheduleRules());
        $this->resolveSchedule($request->user(), $validated, $optionalService->branch_id);

        // Turning a service into a transport route must not leave anyone on two routes at once.
        if (($validated['category'] ?? null) === 'transport' && $optionalService->category !== 'transport') {
            $onTwo = $optionalService->students()
                ->whereHas('optionalServices', fn ($q) => $q->where('optional_services.category', 'transport')
                    ->where('optional_services.academic_year_id', $optionalService->academic_year_id))
                ->exists();
            if ($onTwo) {
                return back()->withErrors(['category' => 'Some students in this service already use another transport route. Move them to one route first.']);
            }
        }

        $optionalService->update($validated);

        return back()->with('success', 'Optional service updated. Invoices already generated keep their original amounts.');
    }

    public function destroy(Request $request, OptionalService $optionalService): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($request->user(), $optionalService->branch_id);

        $optionalService->delete();

        return back()->with('success', 'Optional service removed.');
    }

    /**
     * Enrolls a student in an opt-in service. If the student already
     * has an invoice for that service's academic year, the charge is
     * added to it immediately rather than waiting for the next
     * invoice-generation run -- "opted in" and "billed for it" stay in
     * sync without a separate step. A student has one transport route
     * at a time: see StudentServices::enroll().
     */
    public function enrollStudent(Request $request, Student $student): RedirectResponse
    {
        $request->user()->can('manage-invoices') || abort(403);
        $this->assertStudentInScope($request->user(), $student);

        $validated = $request->validate([
            'optional_service_id' => ['required', 'integer'],
            'custom_amount' => self::CUSTOM_AMOUNT_RULES,
        ]);

        $service = OptionalService::whereKey($validated['optional_service_id'])->firstOrFail();
        abort_unless($service->branch_id === $student->branch_id, 422);

        $custom = isset($validated['custom_amount']) ? (float) $validated['custom_amount'] : null;
        $result = StudentServices::enroll($request->user(), $student, $service, $custom);

        $redirect = back()->with('success', $result['replaced']
            ? "Transport changed from {$result['replaced'][0]} to {$service->name}."
            : "Enrolled in {$service->name}.");

        if ($result['already_billed']) {
            $redirect->with('warning', "{$result['already_billed'][0]} is already charged on this period's invoice and was not removed from it. Add a discount on that invoice if the family should not pay for both.");
        }

        return $redirect;
    }

    /**
     * The picker for enrolling several students in one service at once, instead of opening each student's
     * own profile one at a time. Scoped to the service's own branch, current-year roster only.
     */
    public function enrollForm(Request $request, OptionalService $optionalService): Response
    {
        $user = $request->user();
        $user->can('manage-invoices') || abort(403);
        $this->assertBranchInScope($user, $optionalService->branch_id);

        $students = Student::where('branch_id', $optionalService->branch_id)
            ->enrolled()
            ->with(['currentEnrollment.section.grade', 'optionalServices' => fn ($q) => $q->whereKey($optionalService->id)])
            ->orderBy('first_name')
            ->get();

        $sections = $students->map(fn (Student $s) => $s->currentEnrollment->section)
            ->filter()
            ->unique('id')
            ->map(fn ($sec) => ['id' => $sec->id, 'label' => "{$sec->grade->name} {$sec->name}", 'grade' => $sec->grade->name])
            ->sortBy('label')
            ->values();

        return Inertia::render('OptionalServices/EnrollStudents', [
            'service' => [
                'id' => $optionalService->id,
                'name' => $optionalService->name,
                'category' => $optionalService->category,
                'amount' => $optionalService->amount,
                'frequency' => $optionalService->frequency,
            ],
            'sections' => $sections,
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->fullName(),
                'student_id_number' => $s->student_id_number,
                'section_id' => $s->currentEnrollment?->section_id,
                'already_enrolled' => $s->optionalServices->isNotEmpty(),
            ])->values(),
            'result' => session('enroll_result'),
        ]);
    }

    /**
     * Enrolls every selected student in one service in a single action. Each student still goes through
     * StudentServices::enroll() individually -- the same transport-route-replacement and immediate-billing
     * rules as the one-at-a-time path on the student's own profile, never a second, divergent code path.
     * A student already enrolled, or one who turns out to be out of scope, is skipped and reported, not
     * allowed to fail the whole batch -- the same "skip with a reason" discipline Payroll::generate() uses.
     */
    public function bulkEnroll(Request $request, OptionalService $optionalService): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-invoices') || abort(403);
        $this->assertBranchInScope($user, $optionalService->branch_id);

        $validated = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['integer'],
            'custom_amount' => self::CUSTOM_AMOUNT_RULES,
        ]);

        $custom = isset($validated['custom_amount']) ? (float) $validated['custom_amount'] : null;
        $students = Student::where('branch_id', $optionalService->branch_id)
            ->whereIn('id', $validated['student_ids'])
            ->get();

        $enrolled = [];
        $skipped = [];
        foreach ($students as $student) {
            try {
                StudentServices::enroll($user, $student, $optionalService, $custom);
                $enrolled[] = $student->fullName();
            } catch (ValidationException) {
                $skipped[] = $student->fullName();
            }
        }

        $message = count($enrolled) > 0
            ? count($enrolled).' student'.(count($enrolled) === 1 ? '' : 's').' enrolled in '.$optionalService->name.'.'
            : 'No students were enrolled -- everyone selected was already in this service.';

        return back()->with('success', $message)->with('enroll_result', ['enrolled' => $enrolled, 'skipped' => $skipped]);
    }

    /** Sets or clears this student's own price for a service they are enrolled in. */
    public function updateStudentService(Request $request, Student $student, OptionalService $optionalService): RedirectResponse
    {
        $request->user()->can('manage-invoices') || abort(403);
        $this->assertStudentInScope($request->user(), $student);
        abort_unless($optionalService->branch_id === $student->branch_id, 404);

        $validated = $request->validate(['custom_amount' => self::CUSTOM_AMOUNT_RULES]);
        $custom = isset($validated['custom_amount']) ? (float) $validated['custom_amount'] : null;

        StudentServices::setCustomAmount($request->user(), $student, $optionalService, $custom);

        return back()->with('success', $custom === null
            ? "{$optionalService->name} now uses the standard price. Invoices already generated keep their original amounts."
            : "Price for this student set. Invoices already generated keep their original amounts.");
    }

    /**
     * Withdraws a student from a service going forward. This
     * deliberately does NOT touch any invoice item already charged for
     * it -- reversing a charge that may already be paid is a billing
     * decision (discount/waive on the invoice), not something this
     * toggle should do silently.
     */
    public function withdrawStudent(Request $request, Student $student, OptionalService $optionalService): RedirectResponse
    {
        $request->user()->can('manage-invoices') || abort(403);
        $this->assertStudentInScope($request->user(), $student);

        $student->optionalServices()->detach($optionalService->id);
        AuditLog::record($request->user(), 'student.service_withdrawn', $student, ['service' => $optionalService->name], null);

        return back()->with('success', "Removed from {$optionalService->name}.");
    }

    /** period_id/teacher_id/days_of_week only ever mean anything for an after-school programme (category
     * 'asp'), but are validated whenever given regardless of category -- simpler than two divergent rule
     * sets, and the frontend only ever shows these fields once 'asp' is chosen anyway. */
    private static function scheduleRules(): array
    {
        return [
            'period_id' => ['nullable', 'integer'],
            'teacher_id' => ['nullable', 'integer'],
            'days_of_week' => ['nullable', 'array', 'max:7'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
        ];
    }

    /** Resolved strictly within scope: a period must belong to this school and be a 'teaching' period (never
     * a break/lunch); a teacher must actually hold the teacher role, in the same branch as the programme. */
    private function resolveSchedule($user, array $validated, ?int $branchId = null): void
    {
        if (! empty($validated['period_id'])) {
            $period = Period::where('school_id', $user->school_id)->find($validated['period_id']);
            abort_unless($period, 422, 'Choose a real period.');
            if ($period->type !== 'teaching') {
                throw ValidationException::withMessages(['period_id' => "{$period->label()} is a {$period->type} period and cannot host a programme."]);
            }
        }

        if (! empty($validated['teacher_id'])) {
            $teacher = User::where('school_id', $user->school_id)
                ->whereHas('roles', fn ($q) => $q->where('name', 'teacher'))
                ->find($validated['teacher_id']);
            abort_unless($teacher, 422, 'Choose a real teacher.');
            if ($branchId !== null) {
                abort_unless($teacher->branch_id === $branchId, 422, 'This teacher is in a different branch than the programme.');
            }
        }
    }

    /** A teacher's own after-school programmes and exactly who is actually enrolled in each -- never every
     * student in the branch, the same "see your own roster, not everyone's" shape Attendance/Timetable
     * already established for a homeroom/subject teacher. */
    public function myPrograms(Request $request): Response
    {
        $user = $request->user();

        $programs = OptionalService::where('teacher_id', $user->id)
            ->where('category', 'asp')
            ->with(['period', 'students' => fn ($q) => $q->orderBy('first_name')])
            ->get()
            ->map(fn (OptionalService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'period' => $s->period?->label(),
                'period_time' => $s->period ? substr($s->period->start_time, 0, 5).'–'.substr($s->period->end_time, 0, 5) : null,
                'days_of_week' => $s->days_of_week ?? [],
                'students' => $s->students->map(fn (Student $st) => [
                    'id' => $st->id,
                    'name' => $st->fullName(),
                ])->values(),
            ]);

        return Inertia::render('OptionalServices/MyPrograms', ['programs' => $programs]);
    }

    private function assertBranchInScope($user, int $branchId): void
    {
        $branch = $user->school->branches()->whereKey($branchId)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $branchId, 403);
        }
    }

    private function assertStudentInScope($user, Student $student): void
    {
        abort_unless($student->school_id === $user->school_id, 403);

        if (! $user->isOrgWide()) {
            abort_unless($student->branch_id === $user->branch_id, 403);
        }
    }
}
