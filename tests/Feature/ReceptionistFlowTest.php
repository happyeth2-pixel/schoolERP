<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The server side of the receptionist workflow: finding a student by a parent's phone number without ever
 * sending phone numbers to the browser, the "you just registered X" confirmation, section cards' display info,
 * and who may see a parent's number on a student's profile.
 */
class ReceptionistFlowTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        // a front-desk branch admin in Main: manages students and parents, nothing else
        $this->registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students', 'manage-parents', 'manage-classes']);
    }

    private function parentWithPhone(Student $child, string $phone): User
    {
        $p = $this->makeUser('parent', null);
        $p->update(['phone' => PhoneNumber::normalize($phone)]);
        $child->guardians()->attach($p->id, ['relationship' => 'mother']);

        return $p;
    }

    private function index(User $user, array $query = []): array
    {
        $response = $this->actingAs($user)->get('/students'.($query ? '?'.http_build_query($query) : ''))->assertOk();

        return $this->props($response);
    }

    // ------------------------------------------------------------------ phone search (now resolved server-side by
    // StudentController@index/applyStudentSearch, as part of the one ?q= search -- not a separate ?phone= trip)

    private function searchIds(User $user, string $q): array
    {
        return collect($this->index($user, ['q' => $q])['students'])->pluck('id')->all();
    }

    public function test_a_parents_phone_finds_the_student_however_it_is_typed(): void
    {
        $this->parentWithPhone($this->studentA, '0911223344');

        foreach (['0911223344', '+251 911 22 33 44', '251911223344', '911 22 33', '91122'] as $typed) {
            $this->assertSame([$this->studentA->id], $this->searchIds($this->registrar, $typed), "typed as {$typed}");
        }
    }

    public function test_the_emergency_contact_is_searchable_too(): void
    {
        $this->studentA->update(['emergency_contact' => PhoneNumber::normalize('0922334455')]);

        $this->assertSame([$this->studentA->id], $this->searchIds($this->registrar, '0922 33 44'));
    }

    public function test_a_search_that_is_too_short_or_matches_nothing_returns_nothing(): void
    {
        $this->parentWithPhone($this->studentA, '0911223344');

        $this->assertSame([], $this->searchIds($this->registrar, '91'), 'fewer than four digits is not a phone search, and matches no name either');
        $this->assertSame([], $this->searchIds($this->registrar, '0955555555'));
    }

    public function test_nothing_typed_shows_the_most_recently_enrolled_students_not_a_phone_search(): void
    {
        $this->assertSame([$this->studentA->id], collect($this->index($this->registrar)['students'])->pluck('id')->all(), 'the registrar only sees their own branch');
        $this->assertSame([$this->studentA->id, $this->studentB->id], collect($this->index($this->owner)['students'])->pluck('id')->sort()->values()->all(), 'the owner is org-wide');
    }

    public function test_phone_search_stays_inside_the_users_branch(): void
    {
        $this->parentWithPhone($this->studentB, '0933445566');   // East branch; the registrar is in Main

        $this->assertSame([], $this->searchIds($this->registrar, '0933445566'));
        $this->assertSame([$this->studentB->id], $this->searchIds($this->owner, '0933445566'), 'the owner is org-wide');
    }

    public function test_no_phone_number_is_ever_sent_to_the_browser_by_the_list(): void
    {
        $this->parentWithPhone($this->studentA, '0911223344');
        $this->studentA->update(['emergency_contact' => PhoneNumber::normalize('0922334455')]);

        $html = $this->actingAs($this->registrar)->get('/students?q=9112233')->assertOk()->getContent();  // typed as a fragment, so the page URL does not itself contain the full number

        foreach (['911223344', '911 22 33 44', '922334455', '922 33 44 55'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "the students list leaked {$needle}");
        }
    }

    public function test_someone_who_cannot_view_students_gets_nothing_from_the_search(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);

        $this->actingAs($teacher)->get('/students?q=0911223344')->assertForbidden();
    }

    // ------------------------------------------------------------------ registration confirmation

    private function enrol(User $by, array $extra = [])
    {
        return $this->actingAs($by)->post('/students', array_merge([
            'first_name' => 'Bethlehem', 'father_name' => 'Girma', 'gender' => 'female', 'enrollment_date' => '2026-09-20',
            'section_id' => $this->sectionA->id, 'guardian_mode' => 'new', 'guardian_name' => 'Aster Girma',
            'guardian_phone' => '0911223344', 'guardian_relationship' => 'mother',
        ], $extra));
    }

    public function test_registering_lands_on_a_confirmation_for_that_student_only_once(): void
    {
        $this->enrol($this->registrar)->assertRedirect(route('students.index'))->assertSessionHasNoErrors();
        $new = Student::where('first_name', 'Bethlehem')->firstOrFail();

        $page = $this->props($this->actingAs($this->registrar)->get('/students'));
        $this->assertSame($new->id, $page['justRegistered']['id']);
        $this->assertSame($new->student_id_number, $page['justRegistered']['student_id_number']);
        $this->assertSame('G1 A', $page['justRegistered']['section']);
        $this->assertSame(['Aster Girma'], collect($page['justRegistered']['guardians'])->all());

        // it is shown once: the next visit is the ordinary list
        $this->assertNull($this->props($this->actingAs($this->registrar)->get('/students'))['justRegistered']);
    }

    public function test_a_confirmation_never_names_a_student_outside_the_users_scope(): void
    {
        $this->actingAs($this->registrar)->withSession(['registered_student' => $this->studentB->id]);

        $this->assertNull($this->props($this->actingAs($this->registrar)->withSession(['registered_student' => $this->studentB->id])->get('/students'))['justRegistered']);
    }

    public function test_registration_rules_are_unchanged(): void
    {
        $this->enrol($this->registrar, ['first_name' => ''])->assertSessionHasErrors('first_name');
        $this->enrol($this->registrar, ['gender' => ''])->assertSessionHasErrors('gender');
        $this->enrol($this->registrar, ['guardian_phone' => ''])->assertSessionHasErrors('guardian_phone');
        $this->assertSame(0, Student::where('first_name', 'Bethlehem')->count());

        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->enrol($teacher)->assertForbidden();
    }

    // ------------------------------------------------------------------ section cards + profile

    public function test_section_cards_carry_grade_class_teacher_and_seats_but_no_teacher_contact_details(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $teacher->update(['phone' => PhoneNumber::normalize('0944556677')]);
        $this->sectionA->update(['class_teacher_id' => $teacher->id, 'capacity' => 30]);

        $response = $this->actingAs($this->registrar)->get('/students/create')->assertOk();
        $section = collect($this->props($response)['sections'])->firstWhere('id', $this->sectionA->id);

        $this->assertSame('G1', $section['grade']);
        $this->assertSame('A', $section['name']);
        $this->assertSame($teacher->name, $section['teacher']);
        $this->assertSame(30, $section['capacity']);
        $this->assertArrayHasKey('seats_left', $section);
        $this->assertStringNotContainsString('944556677', $response->getContent());
        $this->assertStringNotContainsString($teacher->email, $response->getContent());
    }

    public function test_a_parents_number_is_on_the_profile_only_for_people_who_manage_students(): void
    {
        $this->parentWithPhone($this->studentA, '0911223344');
        $auditor = $this->makeUser('auditor', null);

        $forRegistrar = $this->props($this->actingAs($this->registrar)->get("/students/{$this->studentA->id}/edit"))['student']['guardians'][0];
        $this->assertSame('0911 22 33 44', $forRegistrar['phone']);

        $forAuditor = $this->props($this->actingAs($auditor)->get("/students/{$this->studentA->id}/edit"))['student']['guardians'][0];
        $this->assertNull($forAuditor['phone'], 'read-only oversight does not get a parent phone number on the profile');
    }
}
