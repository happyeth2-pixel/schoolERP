<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ParentMessage;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Notifications\NewParentMessage;
use App\Support\AttachmentUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Private messaging between a student's current homeroom teacher (or a branch admin/owner) and that
 * student's guardians -- authorization lives entirely in StudentPolicy (viewMessages/sendMessage), the same
 * split FamilyController already uses for every other parent-facing page. The message TEXT never reaches the
 * audit log (ParentMessage itself is the durable, permanent record -- see its own doc-comment); AuditLog only
 * gets a minimal "something was sent" entry, exactly like DisciplineIncidentController's own pattern.
 */
class ParentMessageController extends Controller
{
    /**
     * The "Messages" nav entry: one list of conversations, not a dead end you can only reach from a
     * student's profile or the attendance screen. Who shows up:
     *  - a parent sees every one of their own children, even ones with no messages yet (so they can start);
     *  - a homeroom teacher sees every student in the class(es) they are class teacher of, for the same reason;
     *  - a branch admin/owner/auditor sees only students who already HAVE a conversation in their scope --
     *    an unfiltered list of every student in the branch would not be a usable inbox. They reach a NEW
     *    conversation with someone who has none yet from that student's own profile instead.
     */
    public function inbox(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasRole('parent') || $user->can('parent-messages') || $user->hasRole('auditor'), 403);

        if ($user->hasRole('parent')) {
            $students = $user->children()->orderBy('first_name')->get();
            $students->load('currentEnrollment.section.classTeacher');
        } elseif ($user->hasRole('teacher')) {
            $sectionIds = Section::where('class_teacher_id', $user->id)->pluck('id');
            $students = Student::whereHas('currentEnrollment', fn ($q) => $q->whereIn('section_id', $sectionIds))
                ->orderBy('first_name')->get();
        } else {
            $studentIds = ParentMessage::query()
                ->whereHas('student', function ($q) use ($user) {
                    $q->where('school_id', $user->school_id);
                    if (! $user->isOrgWide()) {
                        $q->where('branch_id', $user->branch_id);
                    }
                })
                ->distinct()->pluck('student_id');
            $students = Student::whereKey($studentIds)->orderBy('first_name')->get();
        }

        $conversations = $students->map(function (Student $s) use ($user) {
            $last = $s->parentMessages()->with('sender:id,name')->latest()->first();
            $contact = $this->contactFor($user, $s);

            return [
                'student_id' => $s->id,
                'student_name' => $s->fullName(),
                'contact' => $contact,
                'last_message' => $last?->body,
                'last_sender' => $last?->sender->name,
                'last_at' => $last?->created_at?->diffForHumans(),
                'sort_key' => $last?->created_at?->timestamp ?? 0,
            ];
        })->sortByDesc('sort_key')->values()->map(fn ($c) => collect($c)->except('sort_key')->all());

        return Inertia::render('Messages/Inbox', ['conversations' => $conversations]);
    }

    /** A parent asked "who is this going to?" -- so for them only, name the student's current homeroom
     * teacher (the one StudentPolicy::sendMessage actually lets reply as a plain teacher) and their photo,
     * instead of making the parent re-read their own child's name as if that were the useful part; with no
     * teacher assigned yet, say so plainly rather than naming nobody in particular. Nobody else gets a
     * "contact" -- a teacher/admin viewing a thread already knows they themselves are the contact. */
    private function contactFor(User $user, Student $s): ?array
    {
        if (! $user->hasRole('parent')) {
            return null;
        }

        $teacher = $s->currentEnrollment?->section?->classTeacher;

        return $teacher
            ? ['role' => 'homeroom_teacher', 'name' => $teacher->name, 'photo_url' => $teacher->profile_photo_url]
            : ['role' => 'school_office', 'name' => null, 'photo_url' => null];
    }

    public function index(Request $request, Student $student): Response
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewMessages', $student);

        $messages = $student->parentMessages()
            ->with(['sender:id,name,profile_photo_path', 'attachments'])
            ->oldest()
            ->get()
            ->map(fn (ParentMessage $m) => [
                'id' => $m->id,
                'body' => $m->body,
                'sender_name' => $m->sender->name,
                'sender_photo_url' => $m->sender->profile_photo_url,
                'mine' => $m->sender_id === $user->id,
                'sent_at' => $m->created_at->toIso8601String(),
                'images' => $m->attachments->map(fn ($a) => $a->url())->values(),
            ]);

        return Inertia::render('Messages/Thread', [
            'student' => [
                'id' => $student->id,
                'name' => $student->fullName(),
                'class' => $student->currentEnrollment?->section
                    ? trim($student->currentEnrollment->section->grade->name.' '.$student->currentEnrollment->section->name)
                    : null,
            ],
            'contact' => $this->contactFor($user, $student),
            'messages' => $messages,
            'canSend' => Gate::forUser($user)->allows('sendMessage', $student),
            // A parent has no staff "profile" page to go back to (students.edit needs manage-students,
            // which no parent ever holds) -- route them back to their own inbox instead. And a homeroom
            // teacher can reach this page too (sendMessage only needs parent-messages, not manage-students
            // -- see StudentPolicy::sendMessage), so "back to the student's profile" must check the real
            // permission students.edit actually requires, not just "isn't a parent": a teacher who never
            // holds manage-students would otherwise get a 403 clicking Back.
            'backTo' => $user->hasRole('parent')
                ? ['route' => 'messages.index', 'params' => []]
                : ($user->can('manage-students')
                    ? ['route' => 'students.edit', 'params' => [$student->id]]
                    : ['route' => 'dashboard', 'params' => []]),
        ]);
    }

    public function store(Request $request, Student $student): RedirectResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('sendMessage', $student);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']] + AttachmentUpload::rules());

        $message = ParentMessage::create([
            'student_id' => $student->id,
            'sender_id' => $user->id,
            'body' => $validated['body'],
        ]);
        AttachmentUpload::attach($request->file('images', []), $message, $user, 'messages');

        AuditLog::record($user, 'parent_message.sent', $student);
        $this->notifyTheOtherSide($student, $message, $user);

        return back()->with('success', 'Message sent.');
    }

    /** The homeroom teacher's one-tap "tell the whole class" -- e.g. today's homework. One message per
     * student in the section, all sharing a broadcast_id so the thread shows it was sent to everyone. */
    public function broadcast(Request $request, Section $section): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanMessageSection($user, $section);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']] + AttachmentUpload::rules());

        $broadcastId = (string) Str::uuid();
        $enrollments = $section->enrollments()->active()->with('student.guardians')->get();

        $messages = $enrollments->map(function ($enrollment) use ($user, $validated, $section, $broadcastId) {
            $message = ParentMessage::create([
                'student_id' => $enrollment->student_id,
                'sender_id' => $user->id,
                'body' => $validated['body'],
                'section_id' => $section->id,
                'broadcast_id' => $broadcastId,
            ]);

            foreach ($enrollment->student->guardians as $guardian) {
                $guardian->notify(new NewParentMessage($message));
            }

            return $message;
        });

        // Every message in the broadcast shares the same attached images -- uploaded and stored once,
        // not once per recipient.
        if ($messages->isNotEmpty()) {
            AttachmentUpload::attach($request->file('images', []), $messages->first(), $user, 'messages', $messages->slice(1)->all());
        }

        AuditLog::record($user, 'parent_message.broadcast_sent', $section, null, ['recipients' => $enrollments->count()]);

        return back()->with('success', "Sent to {$enrollments->count()} famil".($enrollments->count() === 1 ? 'y' : 'ies').'.');
    }

    /** Staff -> notify every current guardian. A guardian -> notify whoever is responsible for the class
     * right now (the homeroom teacher, or the branch's own principal(s) if there is none yet). */
    private function notifyTheOtherSide(Student $student, ParentMessage $message, $sender): void
    {
        if ($sender->isGuardianOf($student)) {
            $section = $student->currentEnrollment?->section;
            $recipients = $section?->class_teacher_id
                ? collect([$section->classTeacher])
                : \App\Models\User::where('school_id', $student->school_id)
                    ->where('branch_id', $student->branch_id)
                    ->where('status', 'active')
                    ->whereHas('roles', fn ($r) => $r->where('name', 'branch_admin'))
                    ->get();

            $recipients->filter()->each(fn ($u) => $u->notify(new NewParentMessage($message)));

            return;
        }

        $student->guardians->each(fn ($guardian) => $guardian->notify(new NewParentMessage($message)));
    }

    private function assertCanMessageSection($user, Section $section): void
    {
        $user->can('parent-messages') || abort(403);
        $branch = $user->school->branches()->whereKey($section->branch_id)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $section->branch_id, 403);
        }

        if ($user->hasRole('teacher')) {
            abort_unless($section->class_teacher_id === $user->id, 403, 'Only this class\'s homeroom teacher, a branch admin, or the owner can message this class.');
        }
    }
}
