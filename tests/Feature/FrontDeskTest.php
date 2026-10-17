<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The front desk's Find Student, and the pages around it. Finding people must never widen what a person may see: a
 * parent's phone number is only ever sent for the few results on screen, only to people who manage students, and only
 * inside their own branch; the student page offers each action only to someone who already holds its permission.
 */
class FrontDeskTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $registrar;
    private User $motherA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students', 'manage-parents']);
        $this->motherA = $this->makeParent($this->studentA);
        $this->motherA->update(['name' => 'Aster Girma', 'phone' => PhoneNumber::normalize('0911223344')]);
    }

    private function contacts(array $ids, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->registrar)->getJson('/students/contacts?ids='.implode(',', $ids));
    }

    // ================================================================== phone numbers for the results on screen only

    public function test_the_contacts_of_the_results_on_screen_are_returned_with_names_and_phones(): void
    {
        $json = $this->contacts([$this->studentA->id])->assertOk()->json('contacts');

        $this->assertSame('Aster Girma', $json[$this->studentA->id][0]['name']);
        $this->assertSame('Mother', $json[$this->studentA->id][0]['relationship']);
        $this->assertStringContainsString('0911', $json[$this->studentA->id][0]['phone']);
    }

    public function test_a_guardian_without_a_phone_comes_back_with_null_not_a_guess(): void
    {
        $bare = $this->makeParent($this->studentA);   // no phone on record

        $rows = collect($this->contacts([$this->studentA->id])->json('contacts')[$this->studentA->id]);
        $this->assertNull($rows->firstWhere('name', $bare->name)['phone']);
    }

    public function test_students_of_another_branch_are_left_out_even_if_asked_for(): void
    {
        $this->makeParent($this->studentB)->update(['phone' => PhoneNumber::normalize('0922000000')]);

        $json = $this->contacts([$this->studentA->id, $this->studentB->id])->assertOk()->json('contacts');

        $this->assertArrayHasKey($this->studentA->id, $json);
        $this->assertArrayNotHasKey($this->studentB->id, $json, 'no number leaves the branch');
        $this->assertStringNotContainsString('0922', json_encode($json));

        $this->assertArrayHasKey($this->studentB->id, $this->contacts([$this->studentB->id], $this->owner)->json('contacts'), 'an organisation-wide account may');
    }

    public function test_only_people_who_manage_students_get_any_number(): void
    {
        foreach ([$this->makeUser('teacher', $this->branchA->id), $this->makeUser('accountant', $this->branchA->id), $this->makeUser('auditor', null), $this->portalParent()] as $who) {
            $this->contacts([$this->studentA->id], $who)->assertForbidden();
        }
    }

    public function test_at_most_twenty_students_are_answered_and_bad_ids_are_ignored(): void
    {
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->makeStudent($this->branchA, $this->sectionA, "Kid{$i}", 'STU-C-'.$i)->id;
        }

        $json = $this->contacts(array_merge($ids, ['abc', 0, -5]))->assertOk()->json('contacts');

        $this->assertLessThanOrEqual(20, count($json));
        $this->contacts([])->assertOk()->assertJson(['contacts' => []]);
    }

    public function test_the_search_page_itself_never_carries_a_parents_phone_number(): void
    {
        $body = json_encode($this->props($this->actingAs($this->registrar)->get('/students?q=Aster')->assertOk()));

        $this->assertStringNotContainsString('0911223344', $body);
        $this->assertStringNotContainsString('911223344', $body);
        $this->assertStringContainsString('Aster Girma', $body, 'names are there to search by');
    }

    public function test_each_result_carries_grade_and_section_separately_for_the_simple_card(): void
    {
        $row = collect($this->props($this->actingAs($this->registrar)->get('/students?q='.$this->studentA->student_id_number)->assertOk())['students'])->firstWhere('id', $this->studentA->id);

        $this->assertSame('G1', $row['grade']);
        $this->assertSame('A', $row['section_name']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(['Aster Girma'], collect($row['guardians'])->all());
    }

    public function test_a_student_without_a_class_is_still_listed_with_no_grade(): void
    {
        $s = Student::create(['school_id' => $this->school->id, 'branch_id' => $this->branchA->id, 'student_id_number' => 'STU-X-1', 'first_name' => 'Loose', 'father_name' => 'End', 'gender' => 'male', 'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken()]);

        $row = collect($this->props($this->actingAs($this->registrar)->get('/students?q=Loose')->assertOk())['students'])->firstWhere('id', $s->id);
        $this->assertNull($row['grade']);
        $this->assertSame('Unassigned', $row['section']);
    }

    // ================================================================== the student page offers only what is permitted

    private function desk(User $as, ?Student $s = null): array
    {
        return json_decode(json_encode($this->props($this->actingAs($as)->get('/students/'.($s ?? $this->studentA)->id.'/help')->assertOk())), true);
    }

    public function test_a_receptionist_is_offered_profile_editing_the_card_and_the_report_card_but_not_money(): void
    {
        $page = $this->desk($this->registrar);

        $this->assertNotNull($page['profileUrl']);
        $this->assertNotNull($page['viewProfileUrl']);
        $this->assertNotNull($page['cardUrl']);
        $this->assertTrue($page['can']['report_card']);
        $this->assertFalse($page['can']['fees']);
        $this->assertFalse($page['can']['attendance']);
    }

    public function test_an_accountant_is_offered_fees_only(): void
    {
        $accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices', 'verify-payments', 'view-financial-reports']);
        $page = $this->desk($accountant);

        $this->assertTrue($page['can']['fees']);
        $this->assertNull($page['profileUrl']);
        $this->assertNull($page['viewProfileUrl'], 'an accountant does not manage students');
        $this->assertNull($page['cardUrl']);
    }

    public function test_an_auditor_may_look_at_the_profile_and_card_but_is_never_offered_editing(): void
    {
        $page = $this->desk($this->makeUser('auditor', null));

        $this->assertNull($page['profileUrl'], 'no editing offered');
        $this->assertNotNull($page['viewProfileUrl']);
        $this->assertNotNull($page['cardUrl']);
    }

    public function test_the_actions_it_offers_are_all_really_open_to_that_person(): void
    {
        $page = $this->desk($this->registrar);

        foreach (['profileUrl', 'cardUrl'] as $key) {
            $this->actingAs($this->registrar)->get(parse_url($page[$key], PHP_URL_PATH))->assertOk();
        }
        $this->actingAs($this->registrar)->get(parse_url($page['reportCard']['view_url'], PHP_URL_PATH))->assertOk();
    }

    // ================================================================== the parents page

    public function test_the_parents_page_says_whether_children_can_be_opened(): void
    {
        $with = $this->props($this->actingAs($this->registrar)->get('/parents')->assertOk());
        $this->assertTrue($with['canOpenStudents']);

        $only = $this->makeUser('branch_admin', $this->branchA->id, ['manage-parents']);
        $without = $this->props($this->actingAs($only)->get('/parents')->assertOk());
        $this->assertFalse($without['canOpenStudents'], 'they can find a family but not open the student');
        $this->actingAs($only)->get('/students/'.$this->studentA->id.'/help')->assertForbidden();
    }

    private function portalParent(): User
    {
        return $this->motherA;
    }
}
