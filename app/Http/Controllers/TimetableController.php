<?php

namespace App\Http\Controllers;

use App\Models\Period;
use App\Models\Room;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Notifications\TimetableChanged;
use App\Services\TimetableConflicts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weekly timetable scheduling -- academic expansion, Phase 1. A wholly new concept layered on top of the
 * EXISTING `TeachingAssignment` (no day/time component at all) -- it does not require a matching
 * TeachingAssignment to exist, so a one-off substitute teacher can always be scheduled.
 *
 * Conflict handling (the brief's own words: "Allow authorized administrators to override only where
 * explicitly permitted, with warning and audit"): `store()`/`update()` always run
 * `TimetableConflicts::detect()` first. If conflicts exist and the request isn't an explicit, reasoned
 * override from a `timetable-override` holder, they come back as ordinary field-scoped validation errors
 * (never a silent failure). An override records WHY in `override_reason`, which `TimetableSlot::auditAction()`
 * turns into its own distinct, searchable audit action.
 */
class TimetableController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('timetable-view') || $user->can('timetable-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $branchIds = $user->isOrgWide() ? $user->school->branches()->pluck('id') : collect([$user->branch_id]);
        $terms = Term::whereHas('academicYear', fn ($q) => $q->where('school_id', $user->school_id)->where('is_current', true))->orderBy('start_date')->get(['id', 'name']);
        $termId = (int) $request->integer('term_id', $terms->first()?->id ?? 0);

        $scope = in_array($request->string('scope')->toString(), ['section', 'teacher', 'room'], true) ? $request->string('scope')->toString() : 'section';
        $entityId = $request->integer('entity_id', 0) ?: null;

        $slots = collect();
        if ($termId && $entityId) {
            $query = TimetableSlot::where('term_id', $termId)->with(['section.grade', 'subject', 'teacher', 'room', 'period']);
            $query->where(match ($scope) {
                'teacher' => 'teacher_id',
                'room' => 'room_id',
                default => 'section_id',
            }, $entityId);
            $slots = $query->get()->map(fn (TimetableSlot $s) => [
                'id' => $s->id,
                'term_id' => $s->term_id,
                'section_id' => $s->section_id,
                'section' => $s->section->auditLabel(),
                'subject_id' => $s->subject_id,
                'subject' => $s->subject->name,
                'teacher_id' => $s->teacher_id,
                'teacher' => $s->teacher->name,
                'room_id' => $s->room_id,
                'room' => $s->room?->name,
                'period_id' => $s->period_id,
                'period_label' => $s->period->label(),
                'day_of_week' => $s->day_of_week,
                'has_override' => $s->override_reason !== null,
            ]);
        }

        return Inertia::render('Academics/Timetable/Index', [
            'canManage' => $user->can('timetable-manage'),
            'canOverride' => $user->can('timetable-override'),
            'terms' => $terms,
            'termId' => $termId ?: null,
            'scope' => $scope,
            'entityId' => $entityId,
            'periods' => Period::where('school_id', $user->school_id)->orderBy('period_number')->get()->map(fn (Period $p) => ['id' => $p->id, 'label' => $p->label()]),
            'sections' => Section::whereIn('branch_id', $branchIds)->whereHas('academicYear', fn ($q) => $q->where('is_current', true))->with('grade')->orderBy('grade_id')->orderBy('name')->get()->map(fn (Section $s) => ['id' => $s->id, 'label' => $s->auditLabel()]),
            'teachers' => User::where('school_id', $user->school_id)->whereIn('branch_id', $branchIds)->whereHas('roles', fn ($q) => $q->where('name', 'teacher'))->orderBy('name')->get(['id', 'name']),
            'rooms' => Room::whereIn('branch_id', $branchIds)->orderBy('name')->get(['id', 'name']),
            'subjects' => Subject::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name']),
            'slots' => $slots,
        ]);
    }

    private function rules(): array
    {
        return [
            'term_id' => ['required', 'integer'],
            'section_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'teacher_id' => ['required', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'period_id' => ['required', 'integer'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'override_confirmed' => ['nullable', 'boolean'],
            'override_reason' => ['required_if:override_confirmed,true', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** Resolves every foreign key strictly within the actor's own school/branch scope -- never trusts a
     * submitted id without checking it actually belongs here. Returns the resolved models for reuse. */
    private function resolveScoped(User $user, array $validated): array
    {
        $term = Term::whereHas('academicYear', fn ($q) => $q->where('school_id', $user->school_id))->findOrFail($validated['term_id']);
        $branchIds = $user->school->branches()->pluck('id');
        $section = Section::whereIn('branch_id', $branchIds)->findOrFail($validated['section_id']);
        abort_unless($user->isOrgWide() || $section->branch_id === $user->branch_id, 403);
        $subject = Subject::where('school_id', $user->school_id)->findOrFail($validated['subject_id']);
        $teacher = User::where('school_id', $user->school_id)->findOrFail($validated['teacher_id']);
        $period = Period::where('school_id', $user->school_id)->findOrFail($validated['period_id']);
        $room = null;
        if (! empty($validated['room_id'])) {
            $room = Room::whereIn('branch_id', $branchIds)->findOrFail($validated['room_id']);
            if ($room->branch_id !== $section->branch_id) {
                throw ValidationException::withMessages(['room_id' => 'This room belongs to a different branch than the selected class.']);
            }
        }

        return compact('term', 'section', 'subject', 'teacher', 'period', 'room');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('timetable-manage'), 403);

        $validated = $request->validate($this->rules());
        ['term' => $term, 'section' => $section, 'subject' => $subject, 'teacher' => $teacher, 'period' => $period, 'room' => $room] = $this->resolveScoped($user, $validated);

        $overriding = $user->can('timetable-override') && $request->boolean('override_confirmed');
        if (! $overriding) {
            $conflicts = TimetableConflicts::detect($validated);
            if ($conflicts !== []) {
                throw ValidationException::withMessages($conflicts);
            }
        }

        $slot = TimetableSlot::create([
            'school_id' => $user->school_id, 'branch_id' => $section->branch_id, 'term_id' => $term->id,
            'section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'room_id' => $room?->id, 'period_id' => $period->id, 'day_of_week' => $validated['day_of_week'],
            'override_reason' => $overriding ? $validated['override_reason'] : null, 'created_by' => $user->id,
        ]);

        $this->notifyTeacher($slot, $teacher);

        return back()->with('success', $overriding ? 'Timetable slot saved despite the conflict.' : 'Timetable slot added.');
    }

    public function update(Request $request, TimetableSlot $timetableSlot): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('timetable-manage'), 403);
        abort_unless($timetableSlot->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $timetableSlot->branch_id === $user->branch_id, 403);

        $validated = $request->validate($this->rules());
        ['term' => $term, 'section' => $section, 'subject' => $subject, 'teacher' => $teacher, 'period' => $period, 'room' => $room] = $this->resolveScoped($user, $validated);

        $overriding = $user->can('timetable-override') && $request->boolean('override_confirmed');
        if (! $overriding) {
            $conflicts = TimetableConflicts::detect($validated, $timetableSlot->id);
            if ($conflicts !== []) {
                throw ValidationException::withMessages($conflicts);
            }
        }

        $timetableSlot->update([
            'branch_id' => $section->branch_id, 'term_id' => $term->id, 'section_id' => $section->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'room_id' => $room?->id,
            'period_id' => $period->id, 'day_of_week' => $validated['day_of_week'],
            'override_reason' => $overriding ? $validated['override_reason'] : null,
        ]);

        $this->notifyTeacher($timetableSlot, $teacher);

        return back()->with('success', $overriding ? 'Timetable slot saved despite the conflict.' : 'Timetable slot updated.');
    }

    public function destroy(Request $request, TimetableSlot $timetableSlot): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('timetable-manage'), 403);
        abort_unless($timetableSlot->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $timetableSlot->branch_id === $user->branch_id, 403);

        $timetableSlot->delete();

        return back()->with('success', 'Timetable slot removed.');
    }

    /** Any staff account's OWN schedule -- no permission needed, the exact same self-service shape as My
     * Leave/My Performance/My Payslips. */
    public function mine(Request $request): Response
    {
        $user = $request->user();
        $term = Term::whereHas('academicYear', fn ($q) => $q->where('school_id', $user->school_id)->where('is_current', true))->orderBy('start_date')->first();

        $slots = $term ? $user->timetableSlots()->where('term_id', $term->id)->with(['section.grade', 'subject', 'room', 'period'])->get()->map(fn (TimetableSlot $s) => [
            'id' => $s->id,
            'section' => $s->section->auditLabel(),
            'subject' => $s->subject->name,
            'room' => $s->room?->name,
            'period_label' => $s->period->label(),
            'day_of_week' => $s->day_of_week,
        ]) : collect();

        return Inertia::render('Academics/Timetable/Mine', [
            'termName' => $term?->name,
            'slots' => $slots,
        ]);
    }

    private function notifyTeacher(TimetableSlot $slot, User $teacher): void
    {
        $teacher->notify(new TimetableChanged($slot));
    }
}
