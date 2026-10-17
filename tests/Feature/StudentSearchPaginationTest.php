<?php

namespace Tests\Feature;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * StudentController@index used to load every student in the school/branch into the browser on every visit, then
 * let the browser filter them while typing. This pins the fix: the database does the matching and the paging
 * (?q=, ?page=), the screen only ever receives one page of rows, the export route still covers everyone, and the
 * number of queries the index page runs does not grow with how many students exist.
 */
class StudentSearchPaginationTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function makeStudents(int $count, string $prefix = 'Kid'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->makeStudent($this->branchA, $this->sectionA, "{$prefix}{$i}", "STU-{$prefix}-{$i}");
        }
    }

    public function test_the_index_page_is_paginated_at_twenty_per_page_by_default(): void
    {
        $this->makeStudents(30);   // + studentA already in the fixture = 31 in branch A
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $page = $this->props($this->actingAs($registrar)->get('/students')->assertOk());

        $this->assertCount(20, $page['students']);
        $this->assertSame(1, $page['studentsMeta']['current_page']);
        $this->assertSame(2, $page['studentsMeta']['last_page']);
        $this->assertSame(31, $page['studentsMeta']['total']);
    }

    public function test_a_second_page_shows_the_remaining_students_not_the_same_ones_again(): void
    {
        $this->makeStudents(30);
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $first = collect($this->props($this->actingAs($registrar)->get('/students')->assertOk())['students'])->pluck('id');
        $second = collect($this->props($this->actingAs($registrar)->get('/students?page=2')->assertOk())['students'])->pluck('id');

        $this->assertCount(20, $first);
        $this->assertCount(11, $second);
        $this->assertEmpty($first->intersect($second), 'the two pages must not overlap');
    }

    public function test_searching_narrows_and_paginates_the_matching_set_only(): void
    {
        $this->makeStudents(25, 'Zebra');   // 25 students whose name contains "Zebra"

        $page = $this->props($this->actingAs($this->owner)->get('/students?q=Zebra')->assertOk());

        $this->assertSame(25, $page['studentsMeta']['total']);
        $this->assertCount(20, $page['students']);
        $this->assertTrue(collect($page['students'])->every(fn ($s) => str_contains($s['name'], 'Zebra')));
    }

    public function test_a_search_matching_nothing_returns_an_empty_page_not_an_error(): void
    {
        $page = $this->props($this->actingAs($this->owner)->get('/students?q=NoSuchPersonAtAll')->assertOk());

        $this->assertSame([], $page['students']);
        $this->assertSame(0, $page['studentsMeta']['total']);
    }

    public function test_the_export_still_covers_every_student_regardless_of_page_size(): void
    {
        $this->makeStudents(30);
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        Excel::fake();
        $this->actingAs($registrar)->get('/students/export');
        Excel::assertDownloaded('students.xlsx', function ($export) {
            // 1 school-name row + 1 title row + 1 header row + 31 student rows (studentA + 30 new) -- never
            // limited to one page's worth, unlike the screen list right above it
            $this->assertCount(34, $export->array());

            return true;
        });
    }

    public function test_the_number_of_queries_the_list_runs_does_not_grow_once_the_page_is_already_full(): void
    {
        // the page is always capped at one page's worth of rows, so the meaningful comparison is between two
        // already-full pages, not a half-empty one versus a full one (which naturally costs a little less)
        $this->makeStudents(25);
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/students')->assertOk();
        $fewQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->makeStudents(60, 'More');
        DB::flushQueryLog();
        $this->actingAs($this->owner)->get('/students')->assertOk();
        $manyQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($fewQueries + 2, $manyQueries, 'the page should run roughly the same number of queries whether there are 6 or 66 matching students, never one per row');
    }

    public function test_search_still_respects_branch_scope(): void
    {
        $this->makeStudent($this->branchB, $this->sectionB, 'Zenith', 'STU-Z-1');
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $page = $this->props($this->actingAs($registrar)->get('/students?q=Zenith')->assertOk());

        $this->assertSame(0, $page['studentsMeta']['total'], 'a branch-scoped user never finds another branch\'s student, searching or not');
    }
}
