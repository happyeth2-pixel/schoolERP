<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ParentMessage;
use App\Notifications\NewParentMessage;
use App\Support\NotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** Private per-student messaging between whoever is currently responsible for the class (homeroom teacher,
 * branch admin, owner) and a student's guardians -- see StudentPolicy::viewMessages()/sendMessage() and
 * ParentMessageController. */
class ParentMessagingTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    public function test_the_homeroom_teacher_can_view_and_send(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);

        $this->actingAs($teacher)->get(route('students.messages.index', $this->studentA->id))->assertOk();
        $this->actingAs($teacher)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Please bring a pencil tomorrow.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('parent_messages', ['student_id' => $this->studentA->id, 'sender_id' => $teacher->id, 'body' => 'Please bring a pencil tomorrow.']);
    }

    public function test_a_subject_teacher_with_no_homeroom_cannot_message(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);   // teaches the class but is not its homeroom teacher

        $this->actingAs($teacher)->get(route('students.messages.index', $this->studentA->id))->assertForbidden();
        $this->actingAs($teacher)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Hi'])->assertForbidden();
        $this->assertDatabaseMissing('parent_messages', ['student_id' => $this->studentA->id]);
    }

    public function test_branch_admin_and_owner_can_message_regardless_of_homeroom(): void
    {
        $principal = $this->makeUser('branch_admin', $this->branchA->id);

        $this->actingAs($principal)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Hello'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Hello again'])->assertSessionHasNoErrors();

        $this->assertSame(2, ParentMessage::where('student_id', $this->studentA->id)->count());
    }

    public function test_a_branch_admin_of_another_branch_cannot_message(): void
    {
        $otherPrincipal = $this->makeUser('branch_admin', $this->branchB->id);

        $this->actingAs($otherPrincipal)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Hi'])->assertForbidden();
    }

    /** Regression: the thread's "back" link used to always point at students.edit, which needs
     * manage-students -- a permission no parent ever holds, turning "back" into a 403 for every family. */
    public function test_a_guardians_back_link_goes_to_their_inbox_not_the_staff_only_profile_page(): void
    {
        $parent = $this->makeParent($this->studentA);

        $page = $this->props($this->actingAs($parent)->get(route('students.messages.index', $this->studentA->id)));
        $this->assertSame('messages.index', $page['backTo']['route']);
        $this->actingAs($parent)->get(route($page['backTo']['route'], $page['backTo']['params']))->assertOk();
    }

    /** A branch admin (manage-students by default) can actually open the profile the "back" link offers. */
    public function test_a_manage_students_staffs_back_link_goes_to_the_students_profile(): void
    {
        $principal = $this->makeUser('branch_admin', $this->branchA->id);

        $page = $this->props($this->actingAs($principal)->get(route('students.messages.index', $this->studentA->id)));
        $this->assertSame('students.edit', $page['backTo']['route']);
        $this->actingAs($principal)->get(route($page['backTo']['route'], $page['backTo']['params']))->assertOk();
    }

    /**
     * Regression: a homeroom teacher can open this thread (sendMessage only needs parent-messages, see
     * StudentPolicy) but a DEFAULT teacher account never holds manage-students -- so the old "anyone who
     * isn't a parent goes to students.edit" logic sent a teacher's "back" link straight into a 403.
     */
    public function test_a_teachers_back_link_goes_somewhere_they_can_actually_open(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);

        $page = $this->props($this->actingAs($teacher)->get(route('students.messages.index', $this->studentA->id)));
        $this->assertSame('dashboard', $page['backTo']['route']);
        $this->actingAs($teacher)->get(route($page['backTo']['route'], $page['backTo']['params']))->assertOk();
    }

    public function test_a_guardian_can_view_and_send_for_their_own_child_only(): void
    {
        $parent = $this->makeParent($this->studentA);
        $stranger = $this->makeParent($this->studentB);

        $this->actingAs($parent)->get(route('students.messages.index', $this->studentA->id))->assertOk();
        $this->actingAs($parent)->post(route('students.messages.store', $this->studentA->id), ['body' => 'When is the next exam?'])->assertSessionHasNoErrors();

        // a parent who is not this child's guardian is told it does not exist, not that it is forbidden
        $this->actingAs($stranger)->get(route('students.messages.index', $this->studentA->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Hi'])->assertNotFound();
    }

    public function test_an_auditor_can_read_but_never_send(): void
    {
        $auditor = $this->makeUser('auditor', null);

        $this->actingAs($auditor)->get(route('students.messages.index', $this->studentA->id))->assertOk();
        $this->actingAs($auditor)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Hi'])->assertForbidden();
    }

    public function test_a_staff_message_notifies_every_current_guardian(): void
    {
        Notification::fake();
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);
        $mother = $this->makeParent($this->studentA);
        $father = $this->makeParent($this->studentA);

        $this->actingAs($teacher)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Reminder: field trip Friday.']);

        Notification::assertSentTo($mother, NewParentMessage::class);
        Notification::assertSentTo($father, NewParentMessage::class);
    }

    /** Regression: a new notification type that isn't taught to NotificationPresenter falls through to its
     * `default => null` and becomes an unclickable dead end -- exactly what happened here until this was added. */
    public function test_the_new_message_notification_links_straight_to_the_thread(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);
        $parent = $this->makeParent($this->studentA);

        $this->actingAs($parent)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Hello']);

        $presented = NotificationPresenter::present($teacher->notifications()->firstOrFail());
        $this->assertSame(route('students.messages.index', $this->studentA->id), $presented['action_url']);
        $this->actingAs($teacher)->get($presented['action_url'])->assertOk();
    }

    public function test_a_guardian_message_notifies_the_homeroom_teacher(): void
    {
        Notification::fake();
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);
        $parent = $this->makeParent($this->studentA);

        $this->actingAs($parent)->post(route('students.messages.store', $this->studentA->id), ['body' => 'My child was sick yesterday.']);

        Notification::assertSentTo($teacher, NewParentMessage::class);
    }

    public function test_a_guardian_message_with_no_homeroom_teacher_notifies_the_branch_principal(): void
    {
        Notification::fake();
        $principal = $this->makeUser('branch_admin', $this->branchA->id);
        $parent = $this->makeParent($this->studentA);   // sectionA has no class_teacher_id set

        $this->actingAs($parent)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Question about fees.']);

        Notification::assertSentTo($principal, NewParentMessage::class);
    }

    public function test_the_message_text_never_reaches_the_audit_log(): void
    {
        $principal = $this->makeUser('branch_admin', $this->branchA->id);
        $secret = 'this exact sentence must never appear in the audit trail';

        $this->actingAs($principal)->post(route('students.messages.store', $this->studentA->id), ['body' => $secret]);

        $log = AuditLog::where('action', 'parent_message.sent')->firstOrFail();
        $this->assertStringNotContainsString($secret, json_encode($log->toArray()));
    }

    public function test_the_homeroom_teacher_can_message_the_whole_class_at_once(): void
    {
        Notification::fake();
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $parentA = $this->makeParent($this->studentA);
        $parentSecond = $this->makeParent($second);

        $this->actingAs($teacher)->post(route('sections.messages.broadcast', $this->sectionA->id), ['body' => "Today's homework: read chapter 3."])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ParentMessage::where('section_id', $this->sectionA->id)->count());
        $broadcastIds = ParentMessage::where('section_id', $this->sectionA->id)->pluck('broadcast_id')->unique();
        $this->assertCount(1, $broadcastIds, 'both rows share the same broadcast id');
        Notification::assertSentTo($parentA, NewParentMessage::class);
        Notification::assertSentTo($parentSecond, NewParentMessage::class);
    }

    public function test_only_the_homeroom_teacher_branch_admin_or_owner_can_broadcast_to_a_class(): void
    {
        $outsider = $this->makeUser('teacher', $this->branchA->id);   // not this section's homeroom teacher

        $this->actingAs($outsider)->post(route('sections.messages.broadcast', $this->sectionA->id), ['body' => 'Hi'])->assertForbidden();
    }

    // ------------------------------------------------------------------ attachments (photos/GIFs)

    private function fakeGif(string $name = 'funny.gif'): UploadedFile
    {
        $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_a_message_can_carry_photos_and_a_gif(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);

        $this->actingAs($teacher)->post(route('students.messages.store', $this->studentA->id), [
            'body' => 'See attached.',
            'images' => [UploadedFile::fake()->image('book.jpg', 800, 600), $this->fakeGif()],
        ])->assertSessionHasNoErrors();

        $message = ParentMessage::where('student_id', $this->studentA->id)->firstOrFail();
        $this->assertCount(2, $message->attachments);
        $this->assertEqualsCanonicalizing(['image/jpeg', 'image/gif'], $message->attachments->pluck('mime')->all());
    }

    public function test_more_than_the_limit_is_refused(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);
        $images = collect(range(1, 5))->map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"))->all();

        $this->actingAs($teacher)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Too many', 'images' => $images])
            ->assertSessionHasErrors('images');
        $this->assertSame(0, ParentMessage::count());
    }

    public function test_a_renamed_non_image_file_is_refused(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);
        $fakeImage = UploadedFile::fake()->createWithContent('not-a-photo.jpg', '<?php echo "pwned"; ?>');

        $this->actingAs($teacher)->post(route('students.messages.store', $this->studentA->id), ['body' => 'x', 'images' => [$fakeImage]])
            ->assertSessionHasErrors('images.0');
        $this->assertSame(0, ParentMessage::count());
    }

    public function test_a_class_broadcasts_attached_photo_is_stored_once_and_shared_by_every_recipient(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');

        $this->actingAs($teacher)->post(route('sections.messages.broadcast', $this->sectionA->id), [
            'body' => "Today's homework photo",
            'images' => [UploadedFile::fake()->image('homework.png')],
        ])->assertSessionHasNoErrors();

        $paths = \App\Models\Attachment::query()->pluck('path');
        $this->assertCount(2, $paths, 'one Attachment row per recipient student');
        $this->assertSame($paths->unique()->count(), 1, 'but only one physical file was stored');
    }

    public function test_the_inbox_lists_a_parents_children_a_teachers_class_and_an_admins_existing_conversations(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->sectionA->update(['class_teacher_id' => $teacher->id]);
        $parent = $this->makeParent($this->studentA);
        $admin = $this->makeUser('branch_admin', $this->branchA->id);

        // nobody has a conversation yet -- the teacher and the parent still see the student (to start one);
        // the admin sees nobody yet, since an unfiltered "every student in the branch" is not a usable inbox
        $teacherIds = collect($this->props($this->actingAs($teacher)->get('/messages'))['conversations'])->pluck('student_id');
        $this->assertContains($this->studentA->id, $teacherIds);
        $parentIds = collect($this->props($this->actingAs($parent)->get('/messages'))['conversations'])->pluck('student_id');
        $this->assertContains($this->studentA->id, $parentIds);
        $adminIds = collect($this->props($this->actingAs($admin)->get('/messages'))['conversations'])->pluck('student_id');
        $this->assertNotContains($this->studentA->id, $adminIds);

        $this->actingAs($teacher)->post(route('students.messages.store', $this->studentA->id), ['body' => 'Hello family']);

        // now that a conversation exists, the admin's inbox picks it up too
        $adminIds = collect($this->props($this->actingAs($admin)->get('/messages'))['conversations'])->pluck('student_id');
        $this->assertContains($this->studentA->id, $adminIds);
    }

    public function test_accountant_cannot_open_the_inbox(): void
    {
        $accountant = $this->makeUser('accountant', $this->branchA->id);

        $this->actingAs($accountant)->get('/messages')->assertForbidden();
    }

    public function test_a_student_with_no_class_yet_can_only_be_messaged_by_branch_admin_or_owner(): void
    {
        $unplaced = \App\Models\Student::create([
            'school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'student_id_number' => 'STU-X',
            'first_name' => 'Unplaced', 'gender' => 'male', 'enrollment_date' => '2026-09-01', 'status' => 'active',
            'id_card_token' => \App\Models\Student::newCardToken(),
        ]);
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $principal = $this->makeUser('branch_admin', $this->branchA->id);

        $this->actingAs($teacher)->post(route('students.messages.store', $unplaced->id), ['body' => 'Hi'])->assertForbidden();
        $this->actingAs($principal)->post(route('students.messages.store', $unplaced->id), ['body' => 'Welcome!'])->assertSessionHasNoErrors();
    }
}
