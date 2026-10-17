<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Section $from;
    private Section $to;
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $school = School::create(['name' => 'S', 'code' => 'S'.Str::random(3)]);
        $branch = Branch::create(['school_id' => $school->id, 'name' => 'Main', 'code' => 'MAIN', 'is_main' => true]);
        $this->owner = User::create(['school_id' => $school->id, 'name' => 'O', 'email' => 'o@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $this->owner->assignTier('owner');
        $y1 = AcademicYear::create(['school_id' => $school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $y2 = AcademicYear::create(['school_id' => $school->id, 'name' => '2027', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_current' => false]);
        $g1 = Grade::create(['school_id' => $school->id, 'name' => 'G1', 'sort_order' => 1]);
        $g2 = Grade::create(['school_id' => $school->id, 'name' => 'G2', 'sort_order' => 2]);
        $this->from = Section::create(['branch_id' => $branch->id, 'grade_id' => $g1->id, 'academic_year_id' => $y1->id, 'name' => 'A']);
        $this->to = Section::create(['branch_id' => $branch->id, 'grade_id' => $g2->id, 'academic_year_id' => $y2->id, 'name' => 'A']);

        foreach (range(1, 3) as $i) {
            $s = Student::create(['school_id' => $school->id, 'branch_id' => $branch->id, 'student_id_number' => "STU-{$i}", 'first_name' => "K{$i}", 'gender' => 'male', 'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken()]);
            Enrollment::create(['student_id' => $s->id, 'section_id' => $this->from->id, 'enrolled_on' => '2026-09-01', 'status' => 'active']);
            $this->students[] = $s;
        }
    }

    private function runPromotion(array $graduating = []): void
    {
        $rows = Enrollment::where('section_id', $this->from->id)->get()->map(fn ($e) => ['enrollment_id' => $e->id, 'graduate' => in_array($e->student_id, $graduating)])->all();
        $this->actingAs($this->owner)->post('/promotions', ['from_section_id' => $this->from->id, 'to_section_id' => $this->to->id, 'promotions' => $rows])->assertSessionHasNoErrors();
    }

    public function test_running_the_same_promotion_twice_promotes_each_student_once_and_says_so(): void
    {
        $this->runPromotion();
        $this->assertStringContainsString('3 promoted', session('success'));
        $this->assertSame(3, Enrollment::where('section_id', $this->to->id)->where('status', 'active')->count());

        $this->runPromotion();
        $this->assertStringContainsString('Nothing to do', session('success'));
        $this->assertStringContainsString('3 already processed', session('success'));
        $this->assertSame(3, Enrollment::where('section_id', $this->to->id)->count(), 'no duplicates');
        $this->assertSame(3, Enrollment::where('section_id', $this->from->id)->where('status', 'completed')->count());
    }

    public function test_a_mixed_second_run_only_processes_the_new_students(): void
    {
        $first = $this->students[0];
        $this->actingAs($this->owner)->post('/promotions', ['from_section_id' => $this->from->id, 'to_section_id' => $this->to->id, 'promotions' => [
            ['enrollment_id' => Enrollment::where('student_id', $first->id)->value('id'), 'graduate' => false],
        ]])->assertSessionHasNoErrors();

        $this->runPromotion(graduating: [$this->students[2]->id]);   // sends all three again

        $this->assertStringContainsString('1 promoted, 1 graduated, 1 already processed', session('success'));
        $this->assertSame('graduated', $this->students[2]->fresh()->status);
        $this->assertSame(2, Enrollment::where('section_id', $this->to->id)->count());
    }

    public function test_a_student_already_placed_in_the_destination_is_not_duplicated(): void
    {
        $s = $this->students[0];
        Enrollment::create(['student_id' => $s->id, 'section_id' => $this->to->id, 'enrolled_on' => '2027-09-01', 'status' => 'active']);

        $this->runPromotion();

        $this->assertSame(1, Enrollment::where('student_id', $s->id)->where('section_id', $this->to->id)->count());
        $this->assertStringContainsString('2 promoted', session('success'));
        $this->assertSame('completed', Enrollment::where('student_id', $s->id)->where('section_id', $this->from->id)->value('status'));
    }

    public function test_a_withdrawn_student_is_left_alone_and_duplicate_rows_in_one_request_count_once(): void
    {
        $this->students[1]->changeStatus('withdrawn');
        $e = Enrollment::where('student_id', $this->students[0]->id)->value('id');

        $this->actingAs($this->owner)->post('/promotions', ['from_section_id' => $this->from->id, 'to_section_id' => $this->to->id, 'promotions' => [
            ['enrollment_id' => $e, 'graduate' => false],
            ['enrollment_id' => $e, 'graduate' => false],
            ['enrollment_id' => Enrollment::where('student_id', $this->students[1]->id)->value('id'), 'graduate' => false],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(1, Enrollment::where('section_id', $this->to->id)->count());
        $this->assertStringContainsString('1 promoted', session('success'));
        $this->assertStringContainsString('1 already processed', session('success'));
        $this->assertSame('withdrawn', $this->students[1]->fresh()->status);
    }

    public function test_enrollments_that_are_not_in_the_named_section_are_reported_not_moved(): void
    {
        $this->actingAs($this->owner)->post('/promotions', ['from_section_id' => $this->from->id, 'to_section_id' => $this->to->id, 'promotions' => [
            ['enrollment_id' => 999999, 'graduate' => false],
        ]])->assertSessionHasNoErrors();
        $this->assertStringContainsString('1 not found', session('success'));
        $this->assertSame(0, Enrollment::where('section_id', $this->to->id)->count());
    }
}
