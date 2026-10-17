<?php

namespace App\Http\Controllers;

use App\Exceptions\DeletionBlockedException;
use App\Models\Branch;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** A branch's own physical rooms -- academic expansion, Phase 1 (Timetable). */
class RoomController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('timetable-view') || $user->can('timetable-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $rooms = Room::whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->with('branch')->withCount('timetableSlots')->orderBy('name')->get()
            ->map(fn (Room $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'branch' => $r->branch->name,
                'capacity' => $r->capacity,
                'slots_count' => $r->timetable_slots_count,
            ]);

        return Inertia::render('Academics/Rooms/Index', [
            'rooms' => $rooms,
            'canManage' => $user->can('timetable-manage'),
            'branches' => $user->isOrgWide() ? Branch::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name']) : [],
            'fixedBranch' => $user->isOrgWide() ? null : ['id' => $user->branch_id, 'name' => $user->branch?->name],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('timetable-manage'), 403);

        $validated = $request->validate(['branch_id' => ['required', 'integer']]);
        $branch = Branch::where('school_id', $user->school_id)->findOrFail($validated['branch_id']);
        abort_unless($user->isOrgWide() || $branch->id === $user->branch_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('rooms')->where('branch_id', $branch->id)],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        Room::create(['branch_id' => $branch->id, ...$validated]);

        return back()->with('success', 'Room added.');
    }

    public function update(Request $request, Room $room): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('timetable-manage'), 403);
        abort_unless($user->isOrgWide() || $room->branch_id === $user->branch_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('rooms')->where('branch_id', $room->branch_id)->ignore($room->id)],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $room->update($validated);

        return back()->with('success', 'Room updated.');
    }

    public function destroy(Request $request, Room $room): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('timetable-manage'), 403);
        abort_unless($user->isOrgWide() || $room->branch_id === $user->branch_id, 403);

        try {
            $room->delete();
        } catch (DeletionBlockedException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('success', 'Room removed.');
    }
}
