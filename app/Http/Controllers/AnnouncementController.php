<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Support\AttachmentUpload;
use App\Support\SchoolCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    /** Everyone signed in (except a role with no school view) reads what is meant for them; managers also see scheduled ones and manage them. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->school_id, 403);
        $manages = $user->can('manage-announcements');
        $today = SchoolCalendar::today()->toDateString();

        $announcements = Announcement::visibleTo($user)
            ->with(['branch:id,name', 'author:id,name,profile_photo_path', 'attachments'])
            ->orderByDesc('publish_on')->orderByDesc('id')
            ->paginate(15)
            ->through(fn (Announcement $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'audience' => $a->audience,
                'audience_label' => Announcement::AUDIENCES[$a->audience],
                'branch_id' => $a->branch_id,
                'branch_name' => $a->branch?->name,
                'publish_on' => $a->publish_on->toDateString(),
                'scheduled' => $a->publish_on->toDateString() > $today,
                'author' => $a->author?->name,
                'author_photo_url' => $a->author?->profile_photo_url,
                'images' => $a->attachments->map(fn ($x) => $x->url())->values(),
                'editable' => $manages && $this->inManagerScope($user, $a),
            ]);

        return Inertia::render('Announcements/Index', [
            'announcements' => $announcements,
            'canManage' => $manages,
            'audiences' => Announcement::AUDIENCES,
            'branches' => $manages
                ? ($user->isOrgWide()
                    ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                    : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']))
                : [],
            'canPostSchoolWide' => $user->isOrgWide(),
            'today' => $today,
        ]);
    }

    /** "Write an announcement" as three short steps: what to say, who and when, then a check. */
    public function create(Request $request): Response
    {
        $user = $request->user();
        $user->can('manage-announcements') || abort(403);

        return Inertia::render('Announcements/Wizard', [
            'audiences' => array_keys(Announcement::AUDIENCES),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'canPostSchoolWide' => $user->isOrgWide(),
            'today' => SchoolCalendar::today()->toDateString(),
            'saved' => session('announcement_saved'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-announcements') || abort(403);

        $data = $this->validated($request, creating: true);
        $data['branch_id'] = $this->resolveBranch($user, $data['branch_id'] ?? null);
        $request->validate(AttachmentUpload::rules());

        $announcement = Announcement::create($data + [
            'school_id' => $user->school_id,
            'created_by' => $user->id,
        ]);
        AttachmentUpload::attach($request->file('images', []), $announcement, $user, 'announcements');

        $scheduled = $data['publish_on'] > SchoolCalendar::today()->toDateString();

        if (! $scheduled) {
            $announcement->notifyAudience();
        }

        return back()->with('success', $scheduled
            ? 'Announcement scheduled — it will appear on its publish date.'
            : 'Announcement published.')->with('announcement_saved', [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'audience' => $announcement->audience,
            'branch' => $announcement->branch?->name,
            'publish_on' => $data['publish_on'],
            'scheduled' => $scheduled,
        ]);
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-announcements') || abort(403);
        abort_unless($this->inManagerScope($user, $announcement), 403);

        $data = $this->validated($request);
        $data['branch_id'] = $this->resolveBranch($user, $data['branch_id'] ?? null);
        $announcement->update($data);

        return back()->with('success', 'Announcement updated.');
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-announcements') || abort(403);
        abort_unless($this->inManagerScope($user, $announcement), 403);

        $announcement->delete();   // archived (soft-deleted) and audited

        return back()->with('success', 'Announcement removed.');
    }

    private function validated(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', Rule::in(array_keys(Announcement::AUDIENCES))],
            'branch_id' => ['nullable', 'integer'],
            'publish_on' => array_filter(['required', 'date', $creating ? 'after_or_equal:'.SchoolCalendar::today()->toDateString() : null]),
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

    private function inManagerScope($user, Announcement $a): bool
    {
        return $a->school_id === $user->school_id && ($user->isOrgWide() || $a->branch_id === $user->branch_id);
    }
}
