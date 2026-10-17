<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Notifications\AttendanceCorrected;
use App\Notifications\StudentMarkedAbsent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class FamilyAndAnnouncementsTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mark(Enrollment $e, string $date, string $status): void
    {
        AttendanceRecord::create(['enrollment_id' => $e->id, 'date' => $date, 'status' => $status, 'marked_by' => $this->owner->id]);
    }

    // ------------------------------------------------------------------ 28: attendance history

    public function test_a_parent_sees_their_childs_days_totals_and_percentage(): void
    {
        $parent = $this->makeParent($this->studentA);
        $e = $this->studentA->currentEnrollment;
        foreach (['2026-09-14' => 'present', '2026-09-15' => 'present', '2026-09-16' => 'late', '2026-10-01' => 'absent'] as $d => $s) {
            $this->mark($e, $d, $s);
        }

        $p = $this->props($this->actingAs($parent)->get('/my/attendance'));

        $this->assertSame($this->studentA->id, $p['student']['id']);
        $this->assertSame(75.0, $p['rate'], '(2 present + 1 late) / 4 recorded days');
        $this->assertSame(2, $p['totals']['present']);
        $this->assertSame(1, $p['totals']['late']);
        $this->assertSame(1, $p['totals']['absent']);
        $this->assertSame(4, $p['totals']['marked']);
        $this->assertSame('2026-10-01', $p['records'][0]['date'], 'newest first');
        $this->assertSame(['2026-10', '2026-09'], collect($p['months'])->all());

        $sep = $this->props($this->actingAs($parent)->get('/my/attendance?month=2026-09'));
        $this->assertSame(100.0, $sep['rate']);
        $this->assertCount(3, $sep['records']);
    }

    public function test_a_child_with_no_records_shows_no_percentage_and_a_parent_cannot_see_another_familys_child(): void
    {
        $parent = $this->makeParent($this->studentA);
        $p = $this->props($this->actingAs($parent)->get('/my/attendance'));
        $this->assertNull($p['rate']);
        $this->assertSame([], collect($p['records'])->all());

        $this->mark($this->studentB->currentEnrollment, '2026-10-01', 'absent');
        $this->actingAs($parent)->get("/my/attendance?student={$this->studentB->id}")->assertNotFound();
        $this->actingAs($parent)->get('/my/attendance?student=999999')->assertNotFound();
    }

    public function test_two_children_can_be_switched_and_staff_have_no_family_page(): void
    {
        $sibling = $this->makeStudent($this->branchA, $this->sectionA, 'Zed', 'STU-Z');
        $parent = $this->makeParent($this->studentA, $sibling);
        $this->mark($sibling->currentEnrollment, '2026-10-01', 'present');

        $p = $this->props($this->actingAs($parent)->get("/my/attendance?student={$sibling->id}"));
        $this->assertSame($sibling->id, $p['student']['id']);
        $this->assertCount(2, $p['children']);

        $this->actingAs($this->owner)->get('/my/attendance')->assertForbidden();
        $this->actingAs($parent)->get('/my/report-cards')->assertOk();
    }

    // ------------------------------------------------------------------ 27: corrections

    public function test_changing_an_absence_notifies_the_parent_and_is_audited(): void
    {
        $parent = $this->makeParent($this->studentA);
        $teacher = $this->makeUser('branch_admin', $this->branchA->id);
        $e = $this->studentA->currentEnrollment;
        $save = fn (string $status) => $this->actingAs($teacher)->post("/attendance/{$this->sectionA->id}", ['date' => '2026-10-15', 'records' => [['enrollment_id' => $e->id, 'status' => $status]]])->assertSessionHasNoErrors();

        $save('absent');
        $this->assertCount(1, $parent->notifications);
        $this->assertSame(StudentMarkedAbsent::class, $parent->notifications->first()->type);
        $this->assertStringContainsString('may correct it', $parent->notifications->first()->data['body']);

        $save('present');
        $parent->refresh();
        $correction = $parent->notifications->firstWhere('type', AttendanceCorrected::class);
        $this->assertNotNull($correction);
        $this->assertSame('Attendance corrected', $correction->data['title']);
        // The notification body now uses the recipient-localized status label (see
        // App\Support\Localization\NotificationLocale and lang/en/attendance.php), not the
        // raw "absent"/"present" enum value, so this checks for "Absent"/"Present".
        $this->assertStringContainsString('from Absent to Present', $correction->data['body']);
        $this->assertStringContainsString('disregard', $correction->data['body']);

        $log = AuditLog::where('action', 'attendance.status_changed')->latest('id')->firstOrFail();
        $this->assertSame(['status' => 'absent'], collect($log->before)->only('status')->all());
        $this->assertSame('present', $log->after['status']);
        $this->assertSame($teacher->id, $log->user_id);
        $this->assertStringContainsString('Almaz', $log->subject_label);

        $count = $parent->notifications()->count();
        $save('present');   // re-saving the same mark does not notify again
        $this->assertSame($count, $parent->notifications()->count());

        $save('sick');   // present -> sick was never an absence the parent heard about: no correction
        $this->assertSame($count, $parent->notifications()->count());
    }

    public function test_an_absence_changed_to_excused_or_late_is_also_corrected(): void
    {
        $parent = $this->makeParent($this->studentA);
        $e = $this->studentA->currentEnrollment;
        foreach (['excused', 'late'] as $to) {
            $this->actingAs($this->owner)->post("/attendance/{$this->sectionA->id}", ['date' => '2026-10-1'.($to === 'late' ? 4 : 3), 'records' => [['enrollment_id' => $e->id, 'status' => 'absent']]]);
            $this->actingAs($this->owner)->post("/attendance/{$this->sectionA->id}", ['date' => '2026-10-1'.($to === 'late' ? 4 : 3), 'records' => [['enrollment_id' => $e->id, 'status' => $to]]]);
        }
        $this->assertSame(2, $parent->notifications()->where('type', AttendanceCorrected::class)->count());
    }

    // ------------------------------------------------------------------ 29: announcements

    public function test_announcements_reach_the_right_audience_branch_and_day(): void
    {
        $parentA = $this->makeParent($this->studentA);
        $parentB = $this->makeParent($this->studentB);
        $teacherA = $this->makeUser('teacher', $this->branchA->id);
        $teacherB = $this->makeUser('teacher', $this->branchB->id);
        $mk = fn (array $o) => Announcement::create($o + ['school_id' => $this->school->id, 'created_by' => $this->owner->id, 'body' => 'x', 'publish_on' => '2026-10-15']);

        $mk(['title' => 'All', 'audience' => 'everyone', 'branch_id' => null]);
        $mk(['title' => 'Parents everywhere', 'audience' => 'parents', 'branch_id' => null]);
        $mk(['title' => 'Staff everywhere', 'audience' => 'staff', 'branch_id' => null]);
        $mk(['title' => 'East parents', 'audience' => 'parents', 'branch_id' => $this->branchB->id]);
        $mk(['title' => 'Main everyone', 'audience' => 'everyone', 'branch_id' => $this->branchA->id]);
        $mk(['title' => 'Tomorrow', 'audience' => 'everyone', 'branch_id' => null, 'publish_on' => '2026-10-16']);

        $titles = fn ($u) => collect($this->props($this->actingAs($u)->get('/announcements'))['announcements']['data'])->pluck('title')->sort()->values()->all();

        $this->assertSame(['All', 'Main everyone', 'Parents everywhere'], $titles($parentA));
        $this->assertSame(['All', 'East parents', 'Parents everywhere'], $titles($parentB));
        $this->assertSame(['All', 'Main everyone', 'Staff everywhere'], $titles($teacherA));
        $this->assertSame(['All', 'Staff everywhere'], $titles($teacherB));
        $this->assertContains('Tomorrow', $titles($this->owner), 'managers see what is scheduled');
        $this->assertNotContains('Tomorrow', $titles($parentA));

        Carbon::setTestNow('2026-10-16 09:00:00');
        $this->assertContains('Tomorrow', $titles($parentA), 'appears on its day');
    }

    public function test_only_managers_can_post_branch_staff_only_to_their_branch_and_delete_archives(): void
    {
        $data = ['title' => 'Hello', 'body' => 'World', 'audience' => 'everyone', 'branch_id' => '', 'publish_on' => '2026-10-15'];

        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->post('/announcements', $data)->assertForbidden();
        $this->actingAs($this->makeParent($this->studentA))->post('/announcements', $data)->assertForbidden();

        $this->actingAs($this->makeUser('branch_admin', $this->branchA->id))->post('/announcements', $data)->assertSessionHasNoErrors();
        $this->assertSame($this->branchA->id, Announcement::firstOrFail()->branch_id, 'forced to their own branch, even when asking for school-wide');

        $this->actingAs($this->owner)->post('/announcements', ['branch_id' => ''] + $data)->assertSessionHasNoErrors();
        $this->assertNull(Announcement::latest('id')->first()->branch_id);

        $this->actingAs($this->owner)->post('/announcements', ['publish_on' => '2026-10-01'] + $data)->assertSessionHasErrors('publish_on');
        $this->actingAs($this->owner)->post('/announcements', ['title' => ''] + $data)->assertSessionHasErrors('title');

        // a branch admin cannot remove another branch's or a school-wide announcement
        $branchB = $this->makeUser('branch_admin', $this->branchB->id);
        $this->actingAs($branchB)->delete('/announcements/'.Announcement::first()->id)->assertForbidden();
        $this->actingAs($branchB)->delete('/announcements/'.Announcement::latest('id')->first()->id)->assertForbidden();

        $id = Announcement::first()->id;
        $this->actingAs($this->owner)->delete("/announcements/{$id}")->assertSessionHasNoErrors();
        $this->assertNull(Announcement::find($id));
        $this->assertNotNull(Announcement::withTrashed()->find($id), 'archived, not erased');
        $this->assertNotNull(AuditLog::where('action', 'announcement.archived')->first());
    }

    // ------------------------------------------------------------------ 29b: announcement notifications

    public function test_publishing_an_announcement_today_notifies_its_audience_immediately(): void
    {
        $parentA = $this->makeParent($this->studentA);
        $parentB = $this->makeParent($this->studentB);
        $teacherA = $this->makeUser('teacher', $this->branchA->id);

        $this->actingAs($this->owner)->post('/announcements', [
            'title' => 'Sports day', 'body' => 'Bring water', 'audience' => 'parents', 'branch_id' => '', 'publish_on' => '2026-10-15',
        ])->assertSessionHasNoErrors();

        $announcement = Announcement::firstOrFail();
        $this->assertNotNull($announcement->notified_at, 'a same-day announcement is notified right away');
        $this->assertCount(1, $parentA->notifications);
        $this->assertSame(\App\Notifications\AnnouncementPublished::class, $parentA->notifications->first()->type);
        $this->assertStringContainsString('Sports day', $parentA->notifications->first()->data['body']);
        $this->assertSame($announcement->id, $parentA->notifications->first()->data['announcement_id']);
        $this->assertCount(1, $parentB->notifications, 'parents audience, school-wide -- both families told');
        $this->assertCount(0, $teacherA->notifications, 'staff are not in the parents audience');
    }

    public function test_a_scheduled_announcement_is_not_notified_until_its_publish_day_arrives(): void
    {
        $parentA = $this->makeParent($this->studentA);

        $this->actingAs($this->owner)->post('/announcements', [
            'title' => 'Next week', 'body' => 'x', 'audience' => 'everyone', 'branch_id' => '', 'publish_on' => '2026-10-20',
        ])->assertSessionHasNoErrors();

        $announcement = Announcement::firstOrFail();
        $this->assertNull($announcement->notified_at);
        $this->assertCount(0, $parentA->notifications);

        Carbon::setTestNow('2026-10-19 09:00:00');
        $this->artisan('announcements:notify-due');
        $this->assertCount(0, $parentA->fresh()->notifications, 'not due yet');

        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->artisan('announcements:notify-due');
        $this->assertCount(1, $parentA->fresh()->notifications, 'due today');
        $this->assertNotNull($announcement->fresh()->notified_at);

        $this->artisan('announcements:notify-due');
        $this->assertCount(1, $parentA->fresh()->notifications, 'running the command again does not double-notify');
    }

    public function test_announcement_notifications_target_the_right_branch(): void
    {
        $parentA = $this->makeParent($this->studentA);
        $parentB = $this->makeParent($this->studentB);
        $teacherA = $this->makeUser('teacher', $this->branchA->id);
        $teacherB = $this->makeUser('teacher', $this->branchB->id);

        $this->actingAs($this->owner)->post('/announcements', [
            'title' => 'Main branch only', 'body' => 'x', 'audience' => 'everyone', 'branch_id' => $this->branchA->id, 'publish_on' => '2026-10-15',
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, $parentA->notifications);
        $this->assertCount(1, $teacherA->notifications);
        $this->assertCount(0, $parentB->notifications, 'other branch\'s family is not told');
        $this->assertCount(0, $teacherB->notifications, 'other branch\'s staff is not told');
    }

    // ------------------------------------------------------------------ 30: student portal

    public function test_student_accounts_cannot_sign_in_because_there_is_no_student_portal(): void
    {
        $student = User::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'name' => 'S', 'email' => 'kid@t.test', 'password' => 'secret-pass-1', 'status' => 'active', 'email_verified_at' => now()]);
        $student->assignTier('student');

        $this->post('/login', ['phone' => 'kid@t.test', 'password' => 'secret-pass-1'])->assertSessionHasErrors('phone');
        $this->assertGuest();

        // an already signed-in student session is ended on its next request
        $this->actingAs($student)->get('/dashboard')->assertRedirect('/login');
    }

    // ------------------------------------------------------------------ 31: branch payment accounts

    public function test_an_invoice_shows_its_branch_accounts_plus_school_wide_ones(): void
    {
        $mk = fn (string $name, ?int $branch) => PaymentAccount::create(['school_id' => $this->school->id, 'branch_id' => $branch, 'type' => 'bank', 'display_name' => $name, 'account_number' => '1']);
        $mk('School-wide', null);
        $mk('Main only', $this->branchA->id);
        $mk('East only', $this->branchB->id);

        $invoiceFor = function ($student) {
            $i = Invoice::create(['student_id' => $student->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-'.$student->id, 'total_amount' => 100, 'status' => 'pending', 'frequency' => 'annual', 'period_start' => '2026-09-01']);
            $i->items()->create(['description' => 'Tuition', 'amount' => 100, 'item_type' => 'fee']);

            return $i;
        };
        $names = fn ($invoice, $user) => collect($this->props($this->actingAs($user)->get("/invoices/{$invoice->id}"))['paymentAccounts'])->pluck('display_name')->sort()->values()->all();

        $this->assertSame(['Main only', 'School-wide'], $names($invoiceFor($this->studentA), $this->makeParent($this->studentA)));
        $this->assertSame(['East only', 'School-wide'], $names($invoiceFor($this->studentB), $this->makeParent($this->studentB)));
    }

    public function test_branch_admins_manage_only_their_own_branchs_accounts_and_owners_choose(): void
    {
        $post = fn ($user, array $extra = []) => $this->actingAs($user)->post('/settings/payment-accounts', $extra + ['display_name' => 'Bank', 'account_number' => '1']);
        $adminA = $this->makeUser('branch_admin', $this->branchA->id);

        $post($adminA, ['branch_id' => $this->branchB->id])->assertSessionHasNoErrors();
        $this->assertSame($this->branchA->id, PaymentAccount::firstOrFail()->branch_id, 'a branch admin cannot add to another branch or school-wide');

        $post($this->owner)->assertSessionHasNoErrors();
        $this->assertNull(PaymentAccount::latest('id')->first()->branch_id);
        $post($this->owner, ['branch_id' => $this->branchB->id]);
        $this->assertSame($this->branchB->id, PaymentAccount::latest('id')->first()->branch_id);

        $wide = PaymentAccount::whereNull('branch_id')->firstOrFail();
        $east = PaymentAccount::where('branch_id', $this->branchB->id)->firstOrFail();
        $this->actingAs($adminA)->delete("/settings/payment-accounts/{$wide->id}")->assertForbidden();
        $this->actingAs($adminA)->delete("/settings/payment-accounts/{$east->id}")->assertForbidden();

        $seen = collect($this->props($this->actingAs($adminA)->get('/settings/school'))['paymentAccounts']);
        $this->assertFalse($seen->contains('id', $east->id), 'another branch\'s account is not listed');
        $this->assertFalse($seen->firstWhere('id', $wide->id)['editable'], 'school-wide accounts are read-only for a branch admin');
    }
}
