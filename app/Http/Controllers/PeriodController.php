<?php

namespace App\Http\Controllers;

use App\Exceptions\DeletionBlockedException;
use App\Models\Period;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** A school's bell-schedule catalog -- academic expansion, Phase 1 (Timetable). Managing it rides on
 * `timetable-manage`, the same way Room management does. */
class PeriodController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('timetable-view') || $user->can('timetable-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        return Inertia::render('Academics/Periods/Index', [
            'periods' => Period::where('school_id', $user->school_id)->withCount('timetableSlots')->orderBy('period_number')->get()
                ->map(fn (Period $p) => [
                    'id' => $p->id,
                    'period_number' => $p->period_number,
                    'name' => $p->name,
                    'type' => $p->type,
                    'start_time' => substr($p->start_time, 0, 5),
                    'end_time' => substr($p->end_time, 0, 5),
                    'slots_count' => $p->timetable_slots_count,
                ]),
            'types' => Period::TYPES,
            'canManage' => $user->can('timetable-manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('timetable-manage'), 403);

        $validated = $request->validate([
            'period_number' => ['required', 'integer', 'min:1', 'max:20', Rule::unique('periods')->where('school_id', $user->school_id)],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(Period::TYPES)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        Period::create(['school_id' => $user->school_id, ...$validated]);

        return back()->with('success', 'Period added.');
    }

    public function update(Request $request, Period $period): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('timetable-manage'), 403);
        abort_unless($period->school_id === $user->school_id, 403);

        $validated = $request->validate([
            'period_number' => ['required', 'integer', 'min:1', 'max:20', Rule::unique('periods')->where('school_id', $user->school_id)->ignore($period->id)],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(Period::TYPES)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        // A period already used to teach something -- an ordinary class or an after-school programme --
        // cannot be turned into a break/lunch out from under it.
        if ($validated['type'] !== 'teaching' && ($period->timetableSlots()->exists() || \App\Models\OptionalService::where('period_id', $period->id)->exists())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['type' => 'This period already has lessons or an after-school programme scheduled in it -- remove them first, or leave it as a teaching period.']);
        }

        $period->update($validated);

        return back()->with('success', 'Period updated.');
    }

    public function destroy(Request $request, Period $period): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('timetable-manage'), 403);
        abort_unless($period->school_id === $user->school_id, 403);

        try {
            $period->delete();
        } catch (DeletionBlockedException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('success', 'Period removed.');
    }
}
