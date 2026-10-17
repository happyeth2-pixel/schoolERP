<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Support\SchoolCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The school calendar -- academic expansion, Phase 3. Mirrors `AnnouncementController` closely: anyone
 * signed in reads what is meant for them (no permission needed to view); `manage-calendar` holders also
 * manage events and see every audience/branch in their scope. One combined page (list + inline dialog), not
 * a separate wizard page like Announcements -- an event has far fewer meaningful decisions to walk through.
 */
class CalendarEventController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->school_id, 403);
        $manages = $user->can('manage-calendar');
        $today = SchoolCalendar::today()->toDateString();

        $events = CalendarEvent::visibleTo($user)
            ->with(['branch:id,name', 'creator:id,name'])
            ->orderBy('start_date')->orderBy('id')
            ->get()
            ->map(fn (CalendarEvent $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'description' => $e->description,
                'event_type' => $e->event_type,
                'event_type_label' => CalendarEvent::EVENT_TYPES[$e->event_type],
                'audience' => $e->audience,
                'audience_label' => CalendarEvent::AUDIENCES[$e->audience],
                'branch_id' => $e->branch_id,
                'branch_name' => $e->branch?->name,
                'start_date' => $e->start_date->toDateString(),
                'end_date' => $e->end_date?->toDateString(),
                'is_past' => ($e->end_date ?? $e->start_date)->toDateString() < $today,
                'creator' => $e->creator?->name,
                'editable' => $manages && $this->inManagerScope($user, $e),
            ]);

        return Inertia::render('Calendar/Index', [
            'upcoming' => $events->reject(fn ($e) => $e['is_past'])->values(),
            'past' => $events->filter(fn ($e) => $e['is_past'])->sortByDesc('start_date')->values(),
            'canManage' => $manages,
            'eventTypes' => CalendarEvent::EVENT_TYPES,
            'audiences' => CalendarEvent::AUDIENCES,
            'branches' => $manages
                ? ($user->isOrgWide()
                    ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                    : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']))
                : [],
            'canPostSchoolWide' => $user->isOrgWide(),
            'today' => $today,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-calendar') || abort(403);

        $data = $this->validated($request);
        $data['branch_id'] = $this->resolveBranch($user, $data['branch_id'] ?? null);

        $event = CalendarEvent::create($data + [
            'school_id' => $user->school_id,
            'created_by' => $user->id,
        ]);

        $event->notifyAudience();

        return back()->with('success', 'Calendar event added.');
    }

    public function update(Request $request, CalendarEvent $calendarEvent): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-calendar') || abort(403);
        abort_unless($this->inManagerScope($user, $calendarEvent), 403);

        $data = $this->validated($request);
        $data['branch_id'] = $this->resolveBranch($user, $data['branch_id'] ?? null);
        $calendarEvent->update($data);

        return back()->with('success', 'Calendar event updated.');
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-calendar') || abort(403);
        abort_unless($this->inManagerScope($user, $calendarEvent), 403);

        $calendarEvent->delete();

        return back()->with('success', 'Calendar event removed.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['required', Rule::in(array_keys(CalendarEvent::EVENT_TYPES))],
            'audience' => ['required', Rule::in(array_keys(CalendarEvent::AUDIENCES))],
            'branch_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }

    /** Branch staff post to their own branch only; org-level accounts pick a branch or the whole school (null). */
    private function resolveBranch($user, ?int $requested): ?int
    {
        if (! $user->isOrgWide()) {
            return $user->branch_id;
        }

        if ($requested !== null) {
            $user->school->branches()->whereKey($requested)->firstOrFail();
        }

        return $requested;
    }

    private function inManagerScope($user, CalendarEvent $e): bool
    {
        return $e->school_id === $user->school_id && ($user->isOrgWide() || $e->branch_id === $user->branch_id);
    }
}
