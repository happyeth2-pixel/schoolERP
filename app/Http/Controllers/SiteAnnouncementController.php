<?php

namespace App\Http\Controllers;

use App\Models\SiteAnnouncement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The banner strip at the top of the public website -- a holiday, a closure, an upcoming event. Kept
    deliberately simple: a short list the owner/branch admin can add to, turn on/off, or delete; only the
    newest currently-showable one is ever displayed (see SiteAnnouncement::scopeCurrentlyShowable). */
class SiteAnnouncementController extends Controller
{
    private function authorize(Request $request): void
    {
        $request->user()->can('manage-school-settings') || abort(403);
    }

    public function index(Request $request): Response
    {
        $this->authorize($request);

        return Inertia::render('SiteAnnouncements/Index', [
            'announcements' => SiteAnnouncement::latest()->get()->map(fn (SiteAnnouncement $a) => [
                'id' => $a->id,
                'message' => $a->message,
                'is_active' => $a->is_active,
                'starts_on' => $a->starts_on?->toDateString(),
                'ends_on' => $a->ends_on?->toDateString(),
                'currently_showing' => $a->is_active
                    && (! $a->starts_on || ! $a->starts_on->isFuture())
                    && (! $a->ends_on || ! $a->ends_on->isPast()),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize($request);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:300'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        SiteAnnouncement::create($validated + ['is_active' => true, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Announcement added.');
    }

    public function toggle(Request $request, SiteAnnouncement $announcement): RedirectResponse
    {
        $this->authorize($request);
        $announcement->update(['is_active' => ! $announcement->is_active]);

        return back();
    }

    public function destroy(Request $request, SiteAnnouncement $announcement): RedirectResponse
    {
        $this->authorize($request);
        $announcement->delete();

        return back()->with('success', 'Announcement removed.');
    }
}
