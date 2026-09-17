<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\User;
use App\Notifications\ComplaintSubmitted;
use App\Notifications\ComplaintUpdated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComplaintController extends Controller
{
    /**
     * One inbox, two audiences: staff with manage-complaints (plus
     * Auditor, read-only) see everything in their scope; a parent or
     * student sees only what they themselves submitted. Nothing here
     * checks the URL differently -- the query just changes based on
     * who's asking.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isStaffView = $user->canViewOrManage('manage-complaints');

        if ($isStaffView) {
            $query = Complaint::where('school_id', $user->school_id);
            if (! $user->isOrgWide()) {
                $query->where('branch_id', $user->branch_id);
            }
        } elseif ($user->hasRole('parent') || $user->hasRole('student')) {
            $query = Complaint::where('submitted_by', $user->id);
        } else {
            abort(403);
        }

        $status = $request->query('status');
        if (in_array($status, ['open', 'in_progress', 'resolved'], true)) {
            $query->where('status', $status);
        }

        $complaints = $query->with(['submittedBy', 'student'])
            ->latest()
            ->get()
            ->map(fn (Complaint $c) => [
                'id' => $c->id,
                'subject' => $c->subject,
                'status' => $c->status,
                'submitted_by' => $c->submittedBy->name,
                'student' => $c->student?->fullName(),
                'created_at' => $c->created_at->diffForHumans(),
            ]);

        return Inertia::render('Complaints/Index', [
            'complaints' => $complaints,
            'isStaffView' => $isStaffView,
            'canManage' => $user->can('manage-complaints'),
            'statusFilter' => $status,
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasRole('parent') || $user->hasRole('student'), 403);

        return Inertia::render('Complaints/Create', [
            'children' => $user->hasRole('parent') ? $user->children->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->fullName(),
            ]) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('parent') || $user->hasRole('student'), 403);

        $validated = $request->validate([
            'student_id' => ['nullable', 'integer'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        if (! empty($validated['student_id'])) {
            abort_unless($user->children()->whereKey($validated['student_id'])->exists(), 403);
        }

        $complaint = Complaint::create([
            'school_id' => $user->school_id,
            'branch_id' => $user->branch_id,
            'submitted_by' => $user->id,
            'student_id' => $validated['student_id'] ?? null,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
        ]);

        $this->recipientsFor($complaint)->each(fn ($staff) => $staff->notify(new ComplaintSubmitted($complaint)));

        return redirect()->route('complaints.index')->with('success', 'Complaint submitted.');
    }

    public function show(Request $request, Complaint $complaint): Response
    {
        $user = $request->user();
        $this->authorizeView($user, $complaint);

        $complaint->load(['submittedBy', 'student', 'resolvedBy']);

        return Inertia::render('Complaints/Show', [
            'complaint' => [
                'id' => $complaint->id,
                'subject' => $complaint->subject,
                'body' => $complaint->body,
                'status' => $complaint->status,
                'resolution_note' => $complaint->resolution_note,
                'resolved_by' => $complaint->resolvedBy?->name,
                'resolved_at' => $complaint->resolved_at?->diffForHumans(),
                'submitted_by' => $complaint->submittedBy->name,
                'student' => $complaint->student?->fullName(),
                'created_at' => $complaint->created_at->format('M j, Y g:ia'),
            ],
            'canManage' => $user->can('manage-complaints'),
        ]);
    }

    public function updateStatus(Request $request, Complaint $complaint): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-complaints') || abort(403);
        abort_unless($complaint->school_id === $user->school_id, 403);
        if (! $user->isOrgWide()) {
            abort_unless($complaint->branch_id === $user->branch_id, 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:open,in_progress,resolved'],
            'resolution_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $complaint->update([
            'status' => $validated['status'],
            'resolution_note' => $validated['resolution_note'] ?? $complaint->resolution_note,
            'resolved_by' => $validated['status'] === 'resolved' ? $user->id : $complaint->resolved_by,
            'resolved_at' => $validated['status'] === 'resolved' ? now() : $complaint->resolved_at,
        ]);

        $complaint->submittedBy->notify(new ComplaintUpdated($complaint));

        return back()->with('success', 'Complaint updated.');
    }

    private function authorizeView($user, Complaint $complaint): void
    {
        if ($complaint->submitted_by === $user->id) {
            return;
        }

        abort_unless($user->canViewOrManage('manage-complaints'), 403);
        abort_unless($complaint->school_id === $user->school_id, 403);
        if (! $user->isOrgWide()) {
            abort_unless($complaint->branch_id === $user->branch_id, 403);
        }
    }

    /**
     * Everyone in the complaint's branch (plus any org-wide account,
     * e.g. Owner) who actually holds manage-complaints -- not just
     * anyone with the same title, since permissions live on the user.
     */
    private function recipientsFor(Complaint $complaint)
    {
        return User::where('school_id', $complaint->school_id)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $complaint->branch_id))
            ->get()
            ->filter(fn ($u) => $u->can('manage-complaints'));
    }
}
