<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\Student;
use App\Models\User;
use App\Notifications\ComplaintSubmitted;
use App\Notifications\ComplaintUpdated;
use App\Support\AttachmentUpload;
use App\Support\SchoolCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        $complaints = $query->with(['submittedBy', 'student.currentEnrollment.section.grade'])
            ->latest()
            ->get()
            ->map(fn (Complaint $c) => [
                'id' => $c->id,
                'subject' => $c->subject,
                'status' => $c->status,
                'submitted_by' => $c->submittedBy->name,
                'student' => $this->studentContext($c->student, $c, $user),
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
        ] + AttachmentUpload::rules());

        $student = null;
        if (! empty($validated['student_id'])) {
            $student = Student::findOrFail($validated['student_id']);
            Gate::authorize('complainAbout', $student);
        }

        // A parent (unlike every staff tier) is deliberately never branch-scoped -- branch_id is always null
        // on their own account, since their children could in principle be in different branches. The branch
        // this complaint is filed under comes from whichever student it's actually about instead; failing
        // that, a guess from the parent's own linked children; a complaint about nothing in particular, from
        // a parent with no children linked yet, genuinely has no branch and is routed to the owner instead
        // (see recipientsFor() and ComplaintsController::index()'s org-wide staff view).
        $branchId = $user->branch_id
            ?? $student?->branch_id
            ?? ($user->hasRole('parent') ? $user->children()->value('branch_id') : null);

        $complaint = Complaint::create([
            'school_id' => $user->school_id,
            'branch_id' => $branchId,
            'submitted_by' => $user->id,
            'student_id' => $validated['student_id'] ?? null,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
        ]);
        AttachmentUpload::attach($request->file('images', []), $complaint, $user, 'complaints');

        $this->recipientsFor($complaint)->each(fn ($staff) => $staff->notify(new ComplaintSubmitted($complaint)));

        return redirect()->route('complaints.index')->with('success', 'Complaint submitted.');
    }

    public function show(Request $request, Complaint $complaint): Response
    {
        $user = $request->user();
        Gate::authorize('view', $complaint);

        $complaint->load(['submittedBy', 'student.currentEnrollment.section.grade', 'resolvedBy', 'attachments']);

        $submitter = $complaint->submittedBy;
        // Other children of the same parent: each one is that child's own student record,
        // so each is checked against the same rule -- ones the viewer may not see are left out, not blurred.
        $siblings = [];
        if ($submitter->hasRole('parent') && $user->id !== $submitter->id) {
            $siblings = $submitter->children()
                ->when($complaint->student_id, fn ($q) => $q->whereKeyNot($complaint->student_id))
                ->get()
                ->filter(fn (Student $s) => Gate::forUser($user)->allows('viewChild', [$complaint, $s]))
                ->map(fn (Student $s) => $this->studentContext($s, $complaint, $user))
                ->values();
        }

        $canSeeContact = Gate::forUser($user)->allows('viewContact', $complaint);

        return Inertia::render('Complaints/Show', [
            'complaint' => [
                'id' => $complaint->id,
                'subject' => $complaint->subject,
                'body' => $complaint->body,
                'status' => $complaint->status,
                'resolution_note' => $complaint->resolution_note,
                'resolved_by' => $complaint->resolvedBy?->name,
                'resolved_at' => $complaint->resolved_at?->diffForHumans(),
                'submitted_by' => $submitter->name,
                'submitted_by_photo_url' => $submitter->profile_photo_url,
                'submitted_by_email' => $canSeeContact ? $submitter->email : null,
                'submitted_by_phone' => $canSeeContact ? \App\Support\PhoneNumber::display($submitter->phone) : null,
                'contact_restricted' => ! $canSeeContact,
                'student' => $this->studentContext($complaint->student, $complaint, $user),
                'siblings' => $siblings,
                'images' => $complaint->attachments->map(fn ($a) => $a->url())->values(),
                'created_at' => SchoolCalendar::dateTime($complaint->created_at, $user),
            ],
            'canManage' => $user->can('manage-complaints'),
        ]);
    }

    /**
     * A student's name plus their current grade+section, so a
     * complaint always shows exactly which class the child is in
     * without staff needing to look them up separately.
     */
    private function studentContext(?Student $student, Complaint $complaint, User $viewer): ?array
    {
        if (! $student) {
            return null;
        }

        // Handling complaints is not the same as being allowed into the child's records.
        if (! Gate::forUser($viewer)->allows('viewChild', [$complaint, $student])) {
            return ['restricted' => true, 'name' => null, 'section' => null];
        }

        $student->loadMissing('currentEnrollment.section.grade');

        return [
            'restricted' => false,
            'name' => $student->fullName(),
            'section' => $student->currentEnrollment
                ? "{$student->currentEnrollment->section->grade->name} {$student->currentEnrollment->section->name}"
                : 'Unassigned',
        ];
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
