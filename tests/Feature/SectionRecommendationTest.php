<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Services\SectionRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Academic expansion, Phase 5: Section Balancing. The brief's own words: "recommendations, not silent
 * auto-assignment." `SectionRecommendation::annotate()` only ever ADDS a `recommended` flag to the section
 * array a picker already renders -- it never changes what is selected, confirmed directly by the service
 * tests below (the input's `id`/order is always preserved, nothing is ever auto-chosen).
 */
class SectionRecommendationTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function row(array $over): array
    {
        return array_merge(['id' => 1, 'grade' => 'Grade 8', 'name' => 'A', 'occupied' => 0, 'full' => false], $over);
    }

    public function test_the_section_with_the_fewest_students_is_recommended(): void
    {
        $sections = collect([
            $this->row(['id' => 1, 'name' => 'A', 'occupied' => 20]),
            $this->row(['id' => 2, 'name' => 'B', 'occupied' => 12]),
            $this->row(['id' => 3, 'name' => 'C', 'occupied' => 25]),
        ]);

        $out = SectionRecommendation::annotate($sections)->keyBy('id');

        $this->assertFalse($out[1]['recommended']);
        $this->assertTrue($out[2]['recommended']);
        $this->assertFalse($out[3]['recommended']);
    }

    public function test_a_full_section_is_never_recommended_even_with_the_lowest_count(): void
    {
        $sections = collect([
            $this->row(['id' => 1, 'name' => 'A', 'occupied' => 5, 'full' => true]),
            $this->row(['id' => 2, 'name' => 'B', 'occupied' => 20]),
        ]);

        $out = SectionRecommendation::annotate($sections)->keyBy('id');

        $this->assertFalse($out[1]['recommended'], 'full, even though it has fewer students');
        $this->assertTrue($out[2]['recommended'], 'the only real candidate left');
    }

    public function test_a_single_section_in_a_grade_gets_no_recommendation(): void
    {
        $sections = collect([$this->row(['id' => 1])]);

        $out = SectionRecommendation::annotate($sections);

        $this->assertFalse($out->first()['recommended'], 'nothing to balance against');
    }

    public function test_a_tie_recommends_exactly_one_section_chosen_deterministically(): void
    {
        $sections = collect([
            $this->row(['id' => 1, 'name' => 'C', 'occupied' => 10]),
            $this->row(['id' => 2, 'name' => 'A', 'occupied' => 10]),
            $this->row(['id' => 3, 'name' => 'B', 'occupied' => 15]),
        ]);

        $out = SectionRecommendation::annotate($sections)->keyBy('id');

        $this->assertSame(1, $out->where('recommended', true)->count(), 'exactly one, never zero or two');
        $this->assertTrue($out[2]['recommended'], 'tied on count -- alphabetically first by name (A before C)');
    }

    public function test_different_grades_are_never_compared_against_each_other(): void
    {
        $sections = collect([
            $this->row(['id' => 1, 'grade' => 'Grade 7', 'occupied' => 5]),
            $this->row(['id' => 2, 'grade' => 'Grade 7', 'occupied' => 30]),
            $this->row(['id' => 3, 'grade' => 'Grade 8', 'occupied' => 1]),
            // Grade 8 has only one section -- no recommendation even though its count is the lowest overall.
        ]);

        $out = SectionRecommendation::annotate($sections)->keyBy('id');

        $this->assertTrue($out[1]['recommended']);
        $this->assertFalse($out[2]['recommended']);
        $this->assertFalse($out[3]['recommended'], 'alone in its grade -- never recommended just for having the lowest count school-wide');
    }

    public function test_sections_of_the_same_grade_in_different_branches_are_never_compared(): void
    {
        $sections = collect([
            $this->row(['id' => 1, 'branch_id' => 1, 'occupied' => 5]),
            $this->row(['id' => 2, 'branch_id' => 2, 'occupied' => 30]),
        ]);

        $out = SectionRecommendation::annotate($sections)->keyBy('id');

        $this->assertFalse($out[1]['recommended'], 'alone in its own branch -- nothing to balance against there');
        $this->assertFalse($out[2]['recommended']);
    }

    public function test_only_current_year_sections_are_ever_candidates(): void
    {
        $sections = collect([
            $this->row(['id' => 1, 'academic_year_id' => 1, 'is_current_year' => true, 'occupied' => 20]),
            $this->row(['id' => 2, 'academic_year_id' => 1, 'is_current_year' => true, 'occupied' => 10]),
            $this->row(['id' => 3, 'academic_year_id' => 2, 'is_current_year' => false, 'occupied' => 1]),
        ]);

        $out = SectionRecommendation::annotate($sections)->keyBy('id');

        $this->assertTrue($out[2]['recommended']);
        $this->assertFalse($out[3]['recommended'], 'a closed year is never a real destination to recommend');
    }

    public function test_annotate_never_changes_ids_order_or_any_other_field(): void
    {
        $sections = collect([
            $this->row(['id' => 1, 'name' => 'A', 'occupied' => 20, 'teacher' => 'Mr X']),
            $this->row(['id' => 2, 'name' => 'B', 'occupied' => 5, 'teacher' => 'Ms Y']),
        ]);

        $out = SectionRecommendation::annotate($sections);

        $this->assertSame([1, 2], $out->pluck('id')->all(), 'order preserved');
        $this->assertSame('Mr X', $out[0]['teacher']);
        $this->assertSame('Ms Y', $out[1]['teacher']);
    }

    public function test_the_real_student_registration_page_sends_the_recommended_flag(): void
    {
        $this->makeStudent($this->branchA, $this->sectionA, 'Extra1', 'STU-E1');
        $this->makeStudent($this->branchA, $this->sectionA, 'Extra2', 'STU-E2');
        $thirdSection = Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'C']);

        $p = $this->props($this->actingAs($this->owner)->get('/students/create'));
        $sections = collect($p['sections'])->keyBy('id');

        $this->assertArrayHasKey('recommended', $sections[$this->sectionA->id]);
        $this->assertTrue($sections[$thirdSection->id]['recommended'], 'the empty new section has fewer students than sectionA');
        $this->assertFalse($sections[$this->sectionA->id]['recommended']);
    }
}
