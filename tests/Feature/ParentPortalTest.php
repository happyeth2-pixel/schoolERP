<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Exam;
use App\Models\Invoice;
use App\Models\Mark;
use App\Models\MarkBatch;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Period;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\SchoolCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * What the parent's home page, attendance page, bills and messages are given -- and, above all, that every one of
 * them is reached only through the guardian link: a parent sees their own children and nothing else.
 */
class ParentPortalTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $parent;
    private Student $second;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $this->parent = $this->makeParent($this->studentA, $this->second);
    }

    private function home(?User $as = null): array
    {
        return json_decode(json_encode($this->props($this->actingAs($as ?? $this->parent)->get('/dashboard')->assertOk())), true);
    }

    private function child(array $page, Student $s): array
    {
        return collect($page['stats']['children'])->firstWhere('id', $s->id);
    }

    private function mark(Student $s, string $date, string $status): void
    {
        AttendanceRecord::create(['enrollment_id' => $s->currentEnrollment->id, 'date' => $date, 'status' => $status, 'marked_by' => $this->owner->id]);
    }

    private function bill(Student $s, float $amount, ?string $label = null, string $frequency = 'annual'): Invoice
    {
        $i = Invoice::create(['student_id' => $s->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-'.(++$this->n), 'total_amount' => $amount, 'status' => 'pending', 'frequency' => $frequency, 'period_label' => $label, 'period_start' => now()->addDays($this->n)->toDateString()]);
        $i->items()->create(['description' => 'Tuition', 'amount' => $amount, 'item_type' => 'fee']);

        return $i->fresh();
    }

    private function pay(Invoice $i, float $amount, string $status = 'verified'): Payment
    {
        return Payment::create(['invoice_id' => $i->id, 'amount' => $amount, 'method' => 'cash', 'status' => $status, 'submitted_by' => $this->parent->id, 'verified_at' => $status === 'verified' ? now() : null, 'receipt_number' => $status === 'verified' ? 'R'.(++$this->n) : null]);
    }

    // ------------------------------------------------------------------ the home page

    public function test_the_home_page_lists_only_this_parents_children_with_their_class_and_first_name(): void
    {
        $stranger = $this->makeParent($this->studentB);

        $page = $this->home();

        $this->assertEqualsCanonicalizing([$this->studentA->id, $this->second->id], array_column($page['stats']['children'], 'id'));
        $one = $this->child($page, $this->second);
        $this->assertSame(['Second', 'G1 A'], [$one['first_name'], $one['class']]);
        $this->assertNotContains($this->studentB->id, array_column($page['stats']['children'], 'id'));
        $this->assertSame([$this->studentB->id], array_column($this->home($stranger)['stats']['children'], 'id'));
    }

    public function test_a_parent_with_no_children_gets_an_empty_list_not_an_error(): void
    {
        $lonely = $this->makeUser('parent', null);

        $this->assertSame([], $this->home($lonely)['stats']['children']);
    }

    public function test_todays_attendance_and_the_last_days_come_newest_first(): void
    {
        $today = SchoolCalendar::today();
        $this->mark($this->studentA, $today->toDateString(), 'late');
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $d) {
            $this->mark($this->studentA, $today->copy()->subDays($d)->toDateString(), $d === 2 ? 'absent' : 'present');
        }

        $child = $this->child($this->home(), $this->studentA);

        $this->assertSame('late', $child['today_status']);
        $this->assertCount(7, $child['recent_attendance'], 'the last few days, not the whole history');
        $this->assertSame($today->toDateString(), $child['recent_attendance'][0]['date']);
        $this->assertSame('absent', $child['recent_attendance'][2]['status']);
        $this->assertNull($this->child($this->home(), $this->second)['today_status'], 'nothing recorded yet today is null, not present');
        $this->assertSame([], $this->child($this->home(), $this->second)['recent_attendance']);
    }

    public function test_fees_are_added_up_across_all_of_a_childs_bills_with_each_bill_listed(): void
    {
        $t1 = $this->bill($this->studentA, 1000, 'Term 1', 'termly');
        $t2 = $this->bill($this->studentA, 500, 'Term 2', 'termly');
        $this->pay($t1, 1000);
        $this->pay($t2, 100);
        $this->pay($t2, 50, 'pending');

        $child = $this->child($this->home(), $this->studentA);

        $this->assertEquals([1500, 1100, 400], [$child['invoice_total'], $child['invoice_paid'], $child['balance']]);
        $this->assertSame(['Term 1', 'Term 2'], array_column($child['bills'], 'label'));
        $this->assertSame(['paid', 'partially_paid'], array_column($child['bills'], 'status'));
        $this->assertSame($t2->id, $child['invoice_id'], 'the bill to open first is the oldest one that still owes something');
        $this->assertSame(1, $child['payments_waiting']);
        $this->assertEquals(50, $child['payments_waiting_amount']);
        $this->assertEquals(0, $child['bills'][0]['balance']);
    }

    public function test_a_refund_puts_money_back_on_what_is_owed_and_a_child_without_bills_has_none(): void
    {
        $bill = $this->bill($this->studentA, 1000);
        $p = $this->pay($bill, 1000);
        PaymentRefund::create(['payment_id' => $p->id, 'amount' => 200, 'kind' => 'refund', 'reason' => 'test', 'refunded_by' => $this->owner->id, 'refunded_at' => now()]);

        $child = $this->child($this->home(), $this->studentA);
        $this->assertEquals([800, 200], [$child['invoice_paid'], $child['balance']]);

        $none = $this->child($this->home(), $this->second);
        $this->assertSame([null, null, null, []], [$none['invoice_total'], $none['invoice_paid'], $none['balance'], $none['bills']]);
        $this->assertNull($none['invoice_id']);
    }

    public function test_another_familys_bills_and_payments_never_appear(): void
    {
        $theirs = $this->bill($this->studentB, 700, 'Theirs');
        $this->pay($theirs, 100, 'pending');
        $mine = $this->bill($this->studentA, 300, 'Mine');

        $json = json_encode($this->home());

        $this->assertStringContainsString('Mine', $json);
        $this->assertStringNotContainsString('Theirs', $json);
        $this->assertStringNotContainsString((string) $theirs->invoice_number, $json);
    }

    // ------------------------------------------------------------------ attendance page

    public function test_the_attendance_page_gives_today_the_children_and_refuses_anyone_elses_child(): void
    {
        $today = SchoolCalendar::today()->toDateString();
        $this->mark($this->studentA, $today, 'sick');
        $this->mark($this->studentA, '2026-08-15', 'present');
        $stranger = $this->makeParent($this->studentB);

        $page = json_decode(json_encode($this->props($this->actingAs($this->parent)->get("/my/attendance?student={$this->studentA->id}&month=2026-08")->assertOk())), true);

        $this->assertSame(['sick', $today], [$page['today']['status'], $page['today']['date']], 'today shows whichever month is being looked at');
        $this->assertEqualsCanonicalizing(['Almaz', 'Second'], array_column($page['children'], 'first_name'));
        $this->assertSame(1, count($page['records']));

        $this->actingAs($this->parent)->get("/my/attendance?student={$this->studentB->id}")->assertNotFound();
        $this->actingAs($stranger)->get("/my/attendance?student={$this->studentA->id}")->assertNotFound();
        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->get('/my/attendance')->assertForbidden();
    }

    public function test_a_child_with_no_record_today_has_a_null_status(): void
    {
        $page = json_decode(json_encode($this->props($this->actingAs($this->parent)->get("/my/attendance?student={$this->second->id}")->assertOk())), true);

        $this->assertNull($page['today']['status']);
    }

    // ------------------------------------------------------------------ report cards and bills

    public function test_the_report_card_hub_and_pdf_are_only_for_own_children(): void
    {
        $hub = json_decode(json_encode($this->props($this->actingAs($this->parent)->get('/my/report-cards')->assertOk())), true);
        $this->assertEqualsCanonicalizing([$this->studentA->id, $this->second->id], array_column($hub['children'], 'id'));

        $this->actingAs($this->parent)->get("/students/{$this->second->id}/report-card")->assertOk();
        $this->actingAs($this->parent)->get("/students/{$this->studentB->id}/report-card")->assertNotFound();
        $this->actingAs($this->parent)->get("/students/{$this->studentB->id}/report-card.pdf")->assertNotFound();
    }

    public function test_a_parent_sees_their_own_bill_with_payments_but_not_anyone_elses(): void
    {
        $mine = $this->bill($this->studentA, 1000);
        $this->pay($mine, 250);
        $theirs = $this->bill($this->studentB, 500);

        $page = json_decode(json_encode($this->props($this->actingAs($this->parent)->get("/invoices/{$mine->id}")->assertOk())), true);

        $this->assertEquals([1000, 250, 750], [$page['invoice']['total'], $page['invoice']['paid'], $page['invoice']['balance']]);
        $this->assertSame(1, count($page['invoice']['payments']));
        $this->assertFalse($page['canManage'], 'the staff tools stay out of a parent\'s reach');
        $this->assertFalse($page['canRefund']);

        $this->actingAs($this->parent)->get("/invoices/{$theirs->id}")->assertNotFound();   // indistinguishable from a bill that does not exist
    }

    public function test_a_parent_cannot_decide_or_change_a_bill(): void
    {
        $mine = $this->bill($this->studentA, 1000);
        $p = $this->pay($mine, 100, 'pending');

        $this->actingAs($this->parent)->post("/payments/{$p->id}/verify")->assertForbidden();
        $this->actingAs($this->parent)->patch("/invoices/{$mine->id}/discount", ['discount_amount' => 500, 'discount_reason' => 'please'])->assertForbidden();
        $this->actingAs($this->parent)->post("/invoices/{$mine->id}/toggle-waive", ['reason' => 'please'])->assertForbidden();

        $this->assertSame('pending', $p->fresh()->status);
    }

    // ------------------------------------------------------------------ messages

    public function test_messages_are_only_ever_the_parents_own(): void
    {
        $other = $this->makeParent($this->studentB);
        $mine = $this->notify($this->parent, 'Mine');
        $theirs = $this->notify($other, 'Theirs');

        $list = json_decode(json_encode($this->props($this->actingAs($this->parent)->get('/notifications')->assertOk())['notificationList']), true);
        $this->assertSame(['Mine'], array_column($list['data'], 'title'));

        $this->actingAs($this->parent)->post("/notifications/{$theirs->id}/read")->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);

        $this->actingAs($this->parent)->post("/notifications/{$mine->id}/read")->assertRedirect();
        $this->assertNotNull($mine->fresh()->read_at);
    }

    public function test_mark_all_read_touches_only_the_parents_own_messages(): void
    {
        $other = $this->makeParent($this->studentB);
        $a = $this->notify($this->parent, 'A');
        $b = $this->notify($this->parent, 'B');
        $theirs = $this->notify($other, 'Theirs');

        $this->assertSame(2, $this->home()['notifications']['unreadCount']);
        $this->actingAs($this->parent)->post('/notifications/read-all')->assertRedirect();

        $this->assertSame(0, $this->home()['notifications']['unreadCount']);
        $this->assertNull($theirs->fresh()->read_at);
    }

    private function notify(User $to, string $title): DatabaseNotification
    {
        return $to->notifications()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'test', 'data' => ['title' => $title, 'body' => 'body'], 'read_at' => null]);
    }

    // ------------------------------------------------------------------ timetable (Phase 9)

    private function slot(Term $term, Subject $subject, User $teacher, int $day = 1): TimetableSlot
    {
        $period = Period::create(['school_id' => $this->school->id, 'period_number' => $day + 10, 'start_time' => '08:00', 'end_time' => '08:40']);

        return TimetableSlot::create([
            'school_id' => $this->school->id, 'branch_id' => $this->sectionA->branch_id, 'term_id' => $term->id,
            'section_id' => $this->sectionA->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'period_id' => $period->id, 'day_of_week' => $day, 'created_by' => $this->owner->id,
        ]);
    }

    public function test_the_timetable_page_shows_the_childs_current_class_schedule_and_refuses_anyone_elses_child(): void
    {
        $term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Math']);
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);
        $this->slot($term, $subject, $teacher, 1);

        $page = json_decode(json_encode($this->props($this->actingAs($this->parent)->get("/my/timetable?student={$this->studentA->id}")->assertOk())), true);

        $this->assertSame('Term 1', $page['termName']);
        $this->assertCount(1, $page['slots']);
        $this->assertSame(['Math', $teacher->name], [$page['slots'][0]['subject'], $page['slots'][0]['teacher']]);

        $stranger = $this->makeParent($this->studentB);
        $this->actingAs($stranger)->get("/my/timetable?student={$this->studentA->id}")->assertNotFound();
        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->get('/my/timetable')->assertForbidden();
    }

    public function test_a_child_with_no_slots_this_term_gets_an_empty_list_not_an_error(): void
    {
        Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);

        $page = json_decode(json_encode($this->props($this->actingAs($this->parent)->get("/my/timetable?student={$this->second->id}")->assertOk())), true);

        $this->assertSame([], $page['slots']);
    }

    // ------------------------------------------------------------------ results (Phase 9)

    private function publishedMark(Term $term, Subject $subject, int $enrollmentId, float $score): MarkBatch
    {
        $exam = Exam::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'name' => 'Midterm', 'type' => 'midterm', 'weight_percent' => 40]);
        $batch = MarkBatch::create(['exam_id' => $exam->id, 'section_id' => $this->sectionA->id, 'subject_id' => $subject->id, 'teacher_id' => $this->owner->id, 'status' => 'draft']);
        Mark::create(['mark_batch_id' => $batch->id, 'enrollment_id' => $enrollmentId, 'score' => $score, 'max_score' => 100]);
        MarkBatch::whereKey($batch->id)->update(['status' => 'published', 'published_at' => now()]);

        return $batch->fresh();
    }

    public function test_results_shows_only_published_marks_for_the_right_child_newest_term_first(): void
    {
        $term1 = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
        $term2 = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 2', 'start_date' => '2027-02-01', 'end_date' => '2027-06-30']);
        $math = Subject::create(['school_id' => $this->school->id, 'name' => 'Math']);
        $this->publishedMark($term1, $math, $this->studentA->currentEnrollment->id, 70);
        $this->publishedMark($term2, $math, $this->studentA->currentEnrollment->id, 85);

        // a draft batch's mark must never appear
        $draftExam = Exam::create(['school_id' => $this->school->id, 'term_id' => $term2->id, 'name' => 'Final', 'type' => 'final', 'weight_percent' => 60]);
        $draftBatch = MarkBatch::create(['exam_id' => $draftExam->id, 'section_id' => $this->sectionA->id, 'subject_id' => $math->id, 'teacher_id' => $this->owner->id, 'status' => 'draft']);
        Mark::create(['mark_batch_id' => $draftBatch->id, 'enrollment_id' => $this->studentA->currentEnrollment->id, 'score' => 99, 'max_score' => 100]);

        $page = json_decode(json_encode($this->props($this->actingAs($this->parent)->get("/my/results?student={$this->studentA->id}")->assertOk())), true);

        $this->assertCount(2, $page['terms']);
        $this->assertSame('Term 2', $page['terms'][0]['term_name'], 'newest term first');
        $this->assertEquals(85, $page['terms'][0]['exams'][0]['score']);
        $this->assertEquals(85.0, $page['terms'][0]['exams'][0]['percentage']);
        $raw = json_encode($page);
        $this->assertStringNotContainsString('"score":99', $raw, 'a draft mark must never appear');

        $stranger = $this->makeParent($this->studentB);
        $this->actingAs($stranger)->get("/my/results?student={$this->studentA->id}")->assertNotFound();
    }

    public function test_a_child_with_no_published_results_gets_an_empty_list(): void
    {
        $page = json_decode(json_encode($this->props($this->actingAs($this->parent)->get("/my/results?student={$this->second->id}")->assertOk())), true);

        $this->assertSame([], $page['terms']);
    }
}
