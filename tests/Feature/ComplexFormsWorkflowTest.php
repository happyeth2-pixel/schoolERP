<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\OptionalService;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Support\SchoolCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The remaining complex forms after their redesign: moving a class up, announcements, extra services, exams and the
 * student's details. The screens changed; these pin what the pages are given and that every rule the old forms
 * relied on still holds (scope, capacity, dates, duplicate prevention, permissions).
 */
class ComplexFormsWorkflowTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Section $next;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $y2 = \App\Models\AcademicYear::create(['school_id' => $this->school->id, 'name' => '2027', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_current' => false]);
        $g2 = \App\Models\Grade::create(['school_id' => $this->school->id, 'name' => 'G2', 'sort_order' => 2]);
        $this->next = Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $g2->id, 'academic_year_id' => $y2->id, 'name' => 'A', 'capacity' => 1]);
    }

    private function page($r): array
    {
        return json_decode(json_encode($this->props($r)), true);
    }

    private function admin(array $permissions, ?int $branch = null): User
    {
        return $this->makeUser('branch_admin', $branch ?? $this->branchA->id, $permissions);
    }

    // ------------------------------------------------------------------ promotion

    public function test_the_promotion_page_describes_each_class_for_the_wizard(): void
    {
        $page = $this->page($this->actingAs($this->owner)->get("/promotions?from={$this->sectionA->id}")->assertOk());

        $a = collect($page['sections'])->firstWhere('id', $this->sectionA->id);
        $this->assertSame(['G1 A', 'G1', 'Main', '2026', true, 1], [$a['class_name'], $a['grade'], $a['branch'], $a['academic_year'], $a['is_current_year'], $a['occupied']]);
        $n = collect($page['sections'])->firstWhere('id', $this->next->id);
        $this->assertSame([1, 1, 0, false], [$n['capacity'], $n['seats_left'], $n['occupied'], $n['is_current_year']]);
        $this->assertSame($this->sectionA->id, $page['fromSectionId']);
        $this->assertCount(1, $page['students']);
        $this->assertStringContainsString('Almaz', $page['students'][0]['name']);
        $this->assertSame(2, $page['branchCount']);
        $this->assertNull($page['result']);
    }

    public function test_a_branch_admin_only_sees_and_promotes_within_their_own_branch(): void
    {
        $admin = $this->admin(['manage-students']);
        $page = $this->page($this->actingAs($admin)->get('/promotions')->assertOk());
        $this->assertNotContains($this->sectionB->id, array_column($page['sections'], 'id'));

        $this->actingAs($admin)->get("/promotions?from={$this->sectionB->id}")->assertForbidden();
        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->get('/promotions')->assertForbidden();
    }

    public function test_running_a_promotion_hands_the_page_the_counts_and_keeps_the_old_summary_sentence(): void
    {
        $e = Enrollment::where('section_id', $this->sectionA->id)->first();
        $r = $this->actingAs($this->owner)->post('/promotions', ['from_section_id' => $this->sectionA->id, 'to_section_id' => $this->next->id, 'promotions' => [['enrollment_id' => $e->id, 'graduate' => false]]]);

        $r->assertRedirect('/promotions');
        $this->assertStringContainsString('1 promoted', session('success'));
        $result = session('promotion_result');
        $this->assertSame([1, 0, 0], [$result['counts']['promoted'], $result['counts']['graduated'], $result['counts']['no_room']]);
        $this->assertSame('G1 A 2026', $result['from']);
        $this->assertSame('G2 A 2027', $result['to']);
    }

    public function test_a_full_class_still_refuses_without_the_override_right_and_says_how_many_did_not_move(): void
    {
        $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $rows = Enrollment::where('section_id', $this->sectionA->id)->get()->map(fn ($e) => ['enrollment_id' => $e->id, 'graduate' => false])->all();
        // no override-section-capacity -- unlike the owner/a principal, this registrar is genuinely capped
        $registrar = $this->admin(['manage-students']);

        $this->actingAs($registrar)->post('/promotions', ['from_section_id' => $this->sectionA->id, 'to_section_id' => $this->next->id, 'promotions' => $rows]);
        $counts = session('promotion_result')['counts'];

        $this->assertSame([1, 1], [$counts['promoted'], $counts['no_room']]);
        $this->assertSame(1, Enrollment::where('section_id', $this->next->id)->count());
    }

    /** An owner/principal holding override-section-capacity is never capped this way: the class is over-filled
     * automatically, exactly as SectionCapacity::grant() now does everywhere else (see YearsTermsCapacityTest). */
    public function test_an_owner_promotes_everyone_into_a_full_class_automatically(): void
    {
        $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $rows = Enrollment::where('section_id', $this->sectionA->id)->get()->map(fn ($e) => ['enrollment_id' => $e->id, 'graduate' => false])->all();

        $this->actingAs($this->owner)->post('/promotions', ['from_section_id' => $this->sectionA->id, 'to_section_id' => $this->next->id, 'promotions' => $rows]);
        $counts = session('promotion_result')['counts'];

        $this->assertSame([2, 0], [$counts['promoted'], $counts['no_room']]);
        $this->assertSame(2, Enrollment::where('section_id', $this->next->id)->count());
    }

    public function test_graduating_everyone_needs_no_destination(): void
    {
        $e = Enrollment::where('section_id', $this->sectionA->id)->first();
        $this->actingAs($this->owner)->post('/promotions', ['from_section_id' => $this->sectionA->id, 'promotions' => [['enrollment_id' => $e->id, 'graduate' => true]]])->assertSessionHasNoErrors();

        $this->assertSame('graduated', $this->studentA->fresh()->status);
        $this->assertSame(1, session('promotion_result')['counts']['graduated']);
    }

    // ------------------------------------------------------------------ announcements

    public function test_only_announcement_managers_can_open_the_wizard_and_branch_admins_are_fixed_to_their_branch(): void
    {
        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->get('/announcements/new')->assertForbidden();

        $mine = $this->page($this->actingAs($this->admin(['manage-announcements']))->get('/announcements/new')->assertOk());
        $this->assertFalse($mine['canPostSchoolWide']);
        $this->assertSame([$this->branchA->id], array_column($mine['branches'], 'id'));
        $this->assertSame(['everyone', 'parents', 'staff'], $mine['audiences']);

        $all = $this->page($this->actingAs($this->owner)->get('/announcements/new')->assertOk());
        $this->assertTrue($all['canPostSchoolWide']);
        $this->assertCount(2, $all['branches']);
        $this->assertSame(SchoolCalendar::today()->toDateString(), $all['today']);
    }

    public function test_publishing_an_announcement_reports_what_was_made_and_a_past_date_is_refused(): void
    {
        $today = SchoolCalendar::today()->toDateString();
        $this->actingAs($this->owner)->post('/announcements', ['title' => 'Closed Friday', 'body' => 'No school.', 'audience' => 'parents', 'branch_id' => null, 'publish_on' => $today])->assertSessionHasNoErrors();

        $saved = session('announcement_saved');
        $this->assertSame(['Closed Friday', 'parents', false], [$saved['title'], $saved['audience'], $saved['scheduled']]);
        $this->assertNull(Announcement::firstOrFail()->branch_id);

        $this->actingAs($this->owner)->post('/announcements', ['title' => 'Old', 'body' => 'x', 'audience' => 'staff', 'publish_on' => SchoolCalendar::today()->subDay()->toDateString()])->assertSessionHasErrors('publish_on');
        $this->assertSame(1, Announcement::count());
    }

    public function test_a_scheduled_announcement_says_so_and_a_branch_admin_cannot_post_school_wide(): void
    {
        $later = SchoolCalendar::today()->addDays(3)->toDateString();
        $admin = $this->admin(['manage-announcements']);
        $this->actingAs($admin)->post('/announcements', ['title' => 'Later', 'body' => 'x', 'audience' => 'everyone', 'branch_id' => $this->branchB->id, 'publish_on' => $later]);

        $this->assertTrue(session('announcement_saved')['scheduled']);
        $this->assertSame($this->branchA->id, Announcement::firstOrFail()->branch_id, 'their own branch, whatever the form says');
    }

    public function test_the_announcement_form_requires_the_same_fields_as_before(): void
    {
        foreach (['title', 'body', 'audience', 'publish_on'] as $field) {
            $payload = ['title' => 'T', 'body' => 'B', 'audience' => 'everyone', 'publish_on' => SchoolCalendar::today()->toDateString()];
            unset($payload[$field]);
            $this->actingAs($this->owner)->post('/announcements', $payload)->assertSessionHasErrors($field);
        }
        $this->actingAs($this->owner)->post('/announcements', ['title' => 'T', 'body' => 'B', 'audience' => 'martians', 'publish_on' => SchoolCalendar::today()->toDateString()])->assertSessionHasErrors('audience');
    }

    // ------------------------------------------------------------------ extra services

    public function test_the_service_wizard_page_lists_existing_services_in_scope_and_needs_the_fee_permission(): void
    {
        OptionalService::create(['branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id, 'name' => 'Transport - Kela', 'category' => 'transport', 'amount' => 300, 'frequency' => 'monthly']);
        OptionalService::create(['branch_id' => $this->branchB->id, 'academic_year_id' => $this->year->id, 'name' => 'East bus', 'category' => 'transport', 'amount' => 300, 'frequency' => 'monthly']);

        $page = $this->page($this->actingAs($this->owner)->get('/optional-services/new')->assertOk());
        $this->assertEqualsCanonicalizing(['Transport - Kela', 'East bus'], array_column($page['existing'], 'name'));

        $mine = $this->page($this->actingAs($this->admin(['manage-fee-structures']))->get('/optional-services/new')->assertOk());
        $this->assertSame(['Transport - Kela'], array_column($mine['existing'], 'name'));
        $this->assertSame([$this->branchA->id], array_column($mine['branches'], 'id'));

        $this->actingAs($this->admin(['manage-students']))->get('/optional-services/new')->assertForbidden();
    }

    public function test_adding_a_service_reports_what_was_made_and_keeps_its_scope_rules(): void
    {
        $body = ['branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id, 'name' => 'Maths club', 'category' => 'asp', 'amount' => '250.50', 'frequency' => 'termly'];
        $this->actingAs($this->owner)->post('/optional-services', $body)->assertSessionHasNoErrors();

        $saved = session('service_saved');
        $this->assertSame(['Maths club', 'asp', 'termly', 'Main', '2026'], [$saved['name'], $saved['category'], $saved['frequency'], $saved['branch'], $saved['academic_year']]);
        $this->assertEquals(250.5, OptionalService::firstOrFail()->amount);

        $this->actingAs($this->admin(['manage-fee-structures']))->post('/optional-services', array_merge($body, ['branch_id' => $this->branchB->id]))->assertForbidden();
        $this->actingAs($this->owner)->post('/optional-services', array_merge($body, ['frequency' => 'weekly']))->assertSessionHasErrors('frequency');
        $this->actingAs($this->owner)->post('/optional-services', array_merge($body, ['amount' => -5]))->assertSessionHasErrors('amount');
    }

    // ------------------------------------------------------------------ exams

    public function test_the_exam_page_tells_each_exam_its_term_and_each_term_its_weight_total(): void
    {
        $term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-30']);
        $this->actingAs($this->owner)->post('/exams', ['term_id' => $term->id, 'name' => 'Mid', 'type' => 'midterm', 'weight_percent' => 30])->assertSessionHasNoErrors();

        $page = $this->page($this->actingAs($this->owner)->get('/exams')->assertOk());
        $this->assertSame($term->id, $page['exams'][0]['term_id']);
        $this->assertEquals(30, $page['terms'][0]['weight_total']);
        $this->assertFalse($page['terms'][0]['complete']);
    }

    public function test_a_terms_weights_still_cannot_pass_one_hundred(): void
    {
        $term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-30']);
        $this->actingAs($this->owner)->post('/exams', ['term_id' => $term->id, 'name' => 'Final', 'type' => 'final', 'weight_percent' => 70]);
        $this->actingAs($this->owner)->post('/exams', ['term_id' => $term->id, 'name' => 'Quiz', 'type' => 'quiz', 'weight_percent' => 40])->assertSessionHasErrors();

        $this->assertSame(1, Exam::count());
    }

    // ------------------------------------------------------------------ student details

    public function test_the_student_update_still_takes_every_field_and_a_status_change_closes_the_enrolment(): void
    {
        $p = [
            'first_name' => 'Almaz', 'father_name' => 'Tesfaye', 'grandfather_name' => 'Bekele', 'mother_name' => 'Tigist', 'gender' => 'female', 'date_of_birth' => '2015-03-01',
            'address' => 'Bole', 'emergency_contact' => '0911223344', 'previous_school' => 'KG One', 'status' => 'active',
        ];
        $this->actingAs($this->owner)->patch("/students/{$this->studentA->id}", $p)->assertSessionHasNoErrors();
        $s = $this->studentA->fresh();
        $this->assertSame(['Bekele', 'Tigist', 'Bole', 'KG One', '+251911223344'], [$s->grandfather_name, $s->mother_name, $s->address, $s->previous_school, $s->emergency_contact]);

        $this->actingAs($this->owner)->patch("/students/{$this->studentA->id}", array_merge($p, ['status' => 'inactive']))->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', Enrollment::where('student_id', $this->studentA->id)->first()->status, 'leaving Active takes the student out of their class');

        $this->actingAs($this->owner)->patch("/students/{$this->studentA->id}", array_merge($p, ['status' => 'withdrawn']))->assertSessionHasErrors('status');
    }
}
