<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\OptionalService;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Notifications\InvoiceGenerated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The guided "create bills" workflow: a read-only preview that is guaranteed to match what the real run then does,
 * the optional choice of billing periods, the reasons students are skipped, duplicate prevention and who may do it.
 * The invoice-creating rules themselves (locks, unique period, frozen lines, notifications) are unchanged.
 */
class InvoiceGenerationTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $accountant;
    private Term $t1;
    private Term $t2;
    private Student $second;
    private Student $third;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices']);
        $this->second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $this->third = $this->makeStudent($this->branchA, $this->sectionA, 'Third', 'STU-A3');
        $this->t1 = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-20']);
        $this->t2 = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 2', 'start_date' => '2027-01-10', 'end_date' => '2027-04-01']);
        $this->fee('Tuition', 1000, 'annual');
        $this->fee('Lunch', 500, 'termly');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fee(string $name, float $amount, string $frequency, ?int $branchId = null): FeeStructure
    {
        return FeeStructure::create(['branch_id' => $branchId ?? $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => $name, 'amount' => $amount, 'frequency' => $frequency]);
    }

    private function query(array $extra = []): array
    {
        return ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id] + $extra;
    }

    private function preview(array $extra = [], ?User $as = null): array
    {
        return json_decode($this->actingAs($as ?? $this->accountant)->getJson('/invoices/generate/preview?'.http_build_query($this->query($extra)))->assertOk()->getContent(), true);
    }

    private function generate(array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->accountant)->post('/invoices/generate', $this->query($extra));
    }

    // ------------------------------------------------------------------ the preview tells the truth

    public function test_the_preview_writes_nothing_and_matches_the_real_run(): void
    {
        $this->studentA->guardians()->attach($this->makeUser('parent', null)->id, ['relationship' => 'mother']);
        $this->second->guardians()->attach($this->makeUser('parent', null)->id, ['relationship' => 'father']);
        Notification::fake();

        $preview = $this->preview();
        $this->assertSame(0, Invoice::count(), 'a preview never creates anything');
        $this->assertSame(3, $preview['result']['students_billed']);
        $this->assertSame(9, $preview['result']['invoices']);               // 3 students x (1 annual + 2 terms)
        $this->assertEquals(6000, $preview['result']['amount']);            // 3 x (1000 + 2 x 500)
        $this->assertSame(6, $preview['result']['parents_notified']);       // 2 parents x 3 bills each
        $this->assertSame(3, $preview['result']['future_invoices'], 'Term 2 has not started yet: its bills are created now, the parents are told now');
        $this->assertNull($preview['blocked']);

        $this->generate()->assertSessionHasNoErrors();
        $run = session('invoice_run');

        $this->assertSame(9, Invoice::count());
        $this->assertSame($preview['result']['invoices'], $run['invoices']);
        $this->assertSame($preview['result']['students_billed'], $run['students_billed']);
        $this->assertEquals($preview['result']['amount'], $run['amount']);
        $this->assertSame($preview['result']['parents_notified'], $run['parents_notified']);
        $this->assertEquals(6000, Invoice::with('items')->get()->sum(fn ($i) => $i->items->sum('amount')), 'the previewed amount is what the bills add up to');
        Notification::assertSentTimes(InvoiceGenerated::class, 6);
        $this->assertEquals($preview['result']['periods'], $run['periods']);
    }

    public function test_a_students_own_price_for_an_optional_service_is_in_the_previewed_amount(): void
    {
        $bus = OptionalService::create(['branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id, 'name' => 'Transport - Kela', 'category' => 'transport', 'amount' => 800, 'frequency' => 'annual']);
        $this->studentA->optionalServices()->attach($bus->id, ['enrolled_on' => '2026-09-01', 'custom_amount' => 650]);

        $preview = $this->preview(['periods' => ['annual']]);

        $this->assertEquals(3000 + 650, $preview['result']['amount']);   // 3 x 1000 tuition + the student's own 650
        $this->generate(['periods' => ['annual']]);
        $this->assertEquals(650, Invoice::where('student_id', $this->studentA->id)->first()->items->firstWhere('optional_service_id', $bus->id)->unit_amount);
    }

    // ------------------------------------------------------------------ duplicates

    public function test_running_it_twice_creates_nothing_the_second_time_and_the_preview_says_so(): void
    {
        $this->generate();
        $count = Invoice::count();

        $again = $this->preview();
        $this->assertSame('nothing_new', $again['blocked']);
        $this->assertSame(0, $again['result']['invoices']);
        $this->assertSame(3, $again['result']['skipped']['already_billed']['count']);

        $this->generate()->assertSessionHasNoErrors();
        $this->assertSame($count, Invoice::count(), 'no period is ever billed twice');
        $this->assertSame(0, session('invoice_run')['invoices']);
        $this->assertSame(3, session('invoice_run')['skipped']['already_billed']['count']);
    }

    public function test_only_what_is_still_missing_is_created_and_the_rest_is_reported_as_already_billed(): void
    {
        $this->generate();
        $newcomer = $this->makeStudent($this->branchA, $this->sectionA, 'Newcomer', 'STU-A4');

        $preview = $this->preview();
        $this->assertSame(3, $preview['result']['invoices']);
        $this->assertSame(1, $preview['result']['students_billed']);
        $this->assertSame(3, $preview['result']['skipped']['already_billed']['count']);
        $this->assertEqualsCanonicalizing(collect([$this->studentA, $this->second, $this->third])->map->fullName()->all(), $preview['result']['skipped']['already_billed']['names']);

        $this->generate();
        $this->assertSame(3, Invoice::where('student_id', $newcomer->id)->count());
        $this->assertSame(12, Invoice::count());
    }

    // ------------------------------------------------------------------ choosing periods

    public function test_only_the_chosen_periods_are_billed_and_the_others_can_follow_without_repeats(): void
    {
        $preview = $this->preview(['periods' => ['term:'.$this->t1->id]]);
        $this->assertSame(3, $preview['result']['invoices']);
        $this->assertSame(['Term 1'], array_column($preview['result']['periods'], 'label'));

        $this->generate(['periods' => ['term:'.$this->t1->id]])->assertSessionHasNoErrors();
        $this->assertSame(3, Invoice::count());
        $this->assertSame(['Term 1'], Invoice::pluck('period_label')->unique()->values()->all());

        $this->generate(['periods' => ['annual', 'term:'.$this->t1->id, 'term:'.$this->t2->id]]);
        $this->assertSame(9, Invoice::count(), 'Term 1 was not billed a second time');
        $this->assertSame(6, session('invoice_run')['invoices']);
    }

    public function test_the_periods_on_offer_are_listed_with_how_many_bills_each_would_make(): void
    {
        $this->generate(['periods' => ['annual']]);

        $byKey = collect($this->preview()['result']['periods'])->keyBy('key');

        $this->assertSame(['created' => 0, 'exists' => 3], ['created' => $byKey['annual']['created'], 'exists' => $byKey['annual']['exists']]);
        $this->assertSame(3, $byKey['term:'.$this->t1->id]['created']);
        $this->assertSame(3, $byKey['term:'.$this->t2->id]['created']);
    }

    public function test_an_unknown_or_empty_period_choice_is_refused_and_nothing_is_created(): void
    {
        $this->generate(['periods' => ['term:99999']])->assertSessionHasErrors('periods');
        $this->generate(['periods' => []])->assertSessionHasErrors('periods');
        $this->actingAs($this->accountant)->getJson('/invoices/generate/preview?'.http_build_query($this->query(['periods' => ['month:2000-01-01']])))->assertStatus(422);

        $this->assertSame(0, Invoice::count());
    }

    // ------------------------------------------------------------------ skipped students, and why

    public function test_a_student_who_left_before_a_period_is_skipped_for_it_with_the_reason(): void
    {
        $this->third->forceFill(['status' => 'withdrawn', 'withdrawn_on' => '2026-10-01'])->save();
        Enrollment::where('student_id', $this->third->id)->update(['status' => 'withdrawn']);
        $term2 = 'term:'.$this->t2->id;

        $all = $this->preview();
        $byKey = collect($all['result']['periods'])->keyBy('key');
        $this->assertSame(3, $all['result']['students_billed'], 'still billed for the periods that began before they left');
        $this->assertSame([2, 1], [$byKey[$term2]['created'], $byKey[$term2]['withdrawn']], 'Term 2 began after they left');

        $onlyTerm2 = $this->preview(['periods' => [$term2]]);
        $this->assertSame(2, $onlyTerm2['result']['students_billed']);
        $this->assertSame(['count' => 1, 'names' => [$this->third->fullName()]], $onlyTerm2['result']['skipped']['left_school']);

        $this->generate(['periods' => [$term2]]);
        $this->assertSame(0, Invoice::where('student_id', $this->third->id)->count());
        $this->assertSame(2, Invoice::count());
    }

    public function test_a_student_with_nothing_to_bill_in_the_chosen_periods_is_reported(): void
    {
        FeeStructure::where('name', 'Lunch')->delete();   // only the yearly fee is left

        $preview = $this->preview(['periods' => ['term:'.$this->t1->id]]);

        $this->assertSame(0, $preview['result']['invoices']);
        $this->assertSame('nothing_new', $preview['blocked']);
        $this->assertSame(3, $preview['result']['skipped']['nothing_to_bill']['count']);
    }

    // ------------------------------------------------------------------ what stops a run

    public function test_the_problems_that_stop_a_run_are_reported_not_thrown(): void
    {
        FeeStructure::query()->delete();
        $this->assertSame('no_fees', $this->preview()['blocked']);

        $this->fee('Lunch', 500, 'termly');
        Term::query()->delete();
        $this->assertSame('no_terms', $this->preview()['blocked']);
        $this->generate()->assertSessionHasErrors('generate');
        $this->assertSame(0, Invoice::count());

        $this->fee('Tuition', 1000, 'annual');
        \App\Models\Enrollment::query()->update(['status' => 'transferred']);
        $this->assertSame('no_students', $this->preview()['blocked']);
    }

    // ------------------------------------------------------------------ who may do it

    public function test_only_people_who_manage_invoices_in_the_right_branch_can_preview_create_or_open_the_workflow(): void
    {
        $noPermission = $this->makeUser('accountant', $this->branchA->id, ['view-financial-reports']);
        $east = $this->makeUser('accountant', $this->branchB->id, ['manage-invoices']);
        $parent = $this->makeParent($this->studentA);

        foreach ([$noPermission, $parent] as $u) {
            $this->actingAs($u)->get('/invoices/new')->assertForbidden();
            $this->actingAs($u)->getJson('/invoices/generate/preview?'.http_build_query($this->query()))->assertForbidden();
            $this->generate([], $u)->assertForbidden();
        }
        $this->actingAs($east)->getJson('/invoices/generate/preview?'.http_build_query($this->query()))->assertForbidden();   // another branch's grade
        $this->generate([], $east)->assertForbidden();

        $this->assertSame(0, Invoice::count());
        $this->actingAs($this->owner)->get('/invoices/new')->assertOk();
        $this->assertNotNull($this->preview([], $this->owner)['result']);
    }

    public function test_a_grade_from_another_school_is_not_found(): void
    {
        $other = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH']);
        $foreign = \App\Models\Grade::create(['school_id' => $other->id, 'name' => 'X', 'sort_order' => 1]);

        $this->actingAs($this->accountant)->getJson('/invoices/generate/preview?'.http_build_query(['branch_id' => $this->branchA->id, 'grade_id' => $foreign->id]))->assertNotFound();
        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $foreign->id])->assertNotFound();
    }

    public function test_the_workflow_page_offers_only_the_users_own_branch_and_how_many_students_each_grade_has(): void
    {
        $page = json_decode(json_encode($this->props($this->actingAs($this->accountant)->get('/invoices/new')->assertOk())), true);

        $this->assertSame([$this->branchA->id], array_column($page['branches'], 'id'));
        $this->assertSame(3, $page['studentCounts']["{$this->branchA->id}-{$this->grade->id}"]);
        $this->assertArrayNotHasKey("{$this->branchB->id}-{$this->grade->id}", $page['studentCounts']);
        $this->assertSame('2026', $page['academicYear']);

        $all = json_decode(json_encode($this->props($this->actingAs($this->owner)->get('/invoices/new'))), true);
        $this->assertCount(2, $all['branches']);
    }

    public function test_the_run_is_audited_with_the_periods_chosen(): void
    {
        $this->generate(['periods' => ['annual']]);

        $log = AuditLog::where('action', 'invoices.generated')->firstOrFail();
        $this->assertSame(3, $log->after['invoices_created']);
        $this->assertSame(['annual'], $log->after['periods']);
    }
}
