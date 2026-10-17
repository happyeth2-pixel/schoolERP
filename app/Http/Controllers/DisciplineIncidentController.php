<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DisciplineIncident;
use App\Models\Student;
use App\Models\User;
use App\Rules\SafeUploadRule;
use App\Support\SafeUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Student discipline -- academic expansion, Phase 7. "incident/category/action/status/attachments under
 * permission protection" (the brief's own words). `discipline-view`/`discipline-manage` are deliberately
 * separate from `manage-students`, the same explicit split Medical Records already established. No destroy
 * route at all: once recorded, an incident is permanent (correct it by editing/resolving, never erase that
 * it happened) -- the same append-only philosophy Payment/Invoice/AuditLog already follow.
 */
class DisciplineIncidentController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('discipline-view') || $user->can('discipline-manage') || $user->hasRole('auditor');
    }

    private function assertStudentInScope(User $user, Student $student): void
    {
        abort_unless($student->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $student->branch_id === $user->branch_id, 403);
    }

    private function assertIncidentInScope(User $user, DisciplineIncident $incident): void
    {
        $incident->loadMissing('student');
        $this->assertStudentInScope($user, $incident->student);
    }

    /** The school-wide/branch-wide dashboard: every incident, filterable by status. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $branchIds = $user->isOrgWide() ? $user->school->branches()->pluck('id') : collect([$user->branch_id]);
        $status = $request->string('status')->toString();

        $incidents = DisciplineIncident::whereHas('student', fn ($q) => $q->where('school_id', $user->school_id)->whereIn('branch_id', $branchIds))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(['student', 'reportedBy'])
            ->orderByDesc('incident_date')
            ->get()
            ->map(fn (DisciplineIncident $i) => $this->present($i));

        return Inertia::render('Discipline/Index', [
            'incidents' => $incidents,
            'status' => $status ?: null,
            'canManage' => $user->can('discipline-manage'),
        ]);
    }

    /** One student's own incident history. */
    public function forStudent(Request $request, Student $student): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $this->assertStudentInScope($user, $student);

        $incidents = $student->disciplineIncidents()->with('reportedBy')->orderByDesc('incident_date')->get()
            ->map(fn (DisciplineIncident $i) => $this->present($i));

        return Inertia::render('Discipline/ForStudent', [
            'student' => ['id' => $student->id, 'name' => $student->fullName(), 'student_id_number' => $student->student_id_number],
            'incidents' => $incidents,
            'categories' => DisciplineIncident::CATEGORIES,
            'actions' => DisciplineIncident::ACTIONS,
            'statuses' => DisciplineIncident::STATUSES,
            'canManage' => $user->can('discipline-manage'),
        ]);
    }

    public function store(Request $request, Student $student): RedirectResponse
    {
        $user = $request->user();
        $user->can('discipline-manage') || abort(403);
        $this->assertStudentInScope($user, $student);

        $validated = $this->validated($request);

        $incident = $student->disciplineIncidents()->create($validated + ['reported_by' => $user->id]);

        if ($request->hasFile('attachment')) {
            $this->storeAttachment($incident, $request->file('attachment'));
        }

        AuditLog::record($user, 'discipline_incident.created', $incident);

        return back()->with('success', 'Incident recorded.');
    }

    public function update(Request $request, DisciplineIncident $incident): RedirectResponse
    {
        $user = $request->user();
        $user->can('discipline-manage') || abort(403);
        $this->assertIncidentInScope($user, $incident);

        $validated = $this->validated($request);
        $wasResolved = $incident->status === 'resolved';
        $nowResolved = $validated['status'] === 'resolved';

        $incident->update($validated + [
            'resolved_by' => ! $wasResolved && $nowResolved ? $user->id : $incident->resolved_by,
            'resolved_at' => ! $wasResolved && $nowResolved ? now() : $incident->resolved_at,
        ]);

        if ($request->hasFile('attachment')) {
            $this->storeAttachment($incident, $request->file('attachment'));
        }

        AuditLog::record($user, 'discipline_incident.updated', $incident);

        return back()->with('success', 'Incident updated.');
    }

    public function document(Request $request, DisciplineIncident $incident): SymfonyResponse
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $this->assertIncidentInScope($user, $incident);
        abort_unless($incident->attachment_path && Storage::disk('local')->exists($incident->attachment_path), 404);

        [$ext, $mime] = SafeUpload::servingType($incident->attachment_path);

        return Storage::disk('local')->response($incident->attachment_path, "incident-{$incident->id}.{$ext}", [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    private function present(DisciplineIncident $i): array
    {
        return [
            'id' => $i->id,
            'student_id' => $i->student_id,
            'student_name' => $i->student->fullName(),
            'category' => $i->category,
            'description' => $i->description,
            'incident_date' => $i->incident_date->toDateString(),
            'action_taken' => $i->action_taken,
            'status' => $i->status,
            'reported_by' => $i->reportedBy?->name,
            'resolved_by' => $i->resolvedBy?->name,
            'resolved_at' => $i->resolved_at?->diffForHumans(),
            'has_attachment' => $i->attachment_path !== null,
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(DisciplineIncident::CATEGORIES)],
            'description' => ['required', 'string', 'max:2000'],
            'incident_date' => ['required', 'date', 'before_or_equal:today'],
            'action_taken' => ['required', Rule::in(DisciplineIncident::ACTIONS)],
            'status' => ['required', Rule::in(DisciplineIncident::STATUSES)],
            'attachment' => ['nullable', 'file', 'max:5120', new SafeUploadRule(['jpg', 'png', 'pdf'])],
        ]);
    }

    private function storeAttachment(DisciplineIncident $incident, $file): void
    {
        $old = $incident->attachment_path;
        $incident->update([
            'attachment_path' => SafeUpload::storePrivate($file, 'discipline-incidents', ['jpg', 'png', 'pdf']),
            'attachment_original_name' => $file->getClientOriginalName(),
        ]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }
    }
}
