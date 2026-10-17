<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * StaffController@index used to load every staff account in the school/branch and let the browser filter a fully
 * loaded array. This pins the fix: the database searches and pages the list (?search=, ?page=), the export still
 * covers everyone regardless of the current page, and the query count stays flat as the roster grows.
 */
class StaffSearchPaginationTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    public function test_the_staff_list_is_paginated(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->makeUser('teacher', $this->branchA->id);
        }

        $page = $this->props($this->actingAs($this->owner)->get('/staff')->assertOk());

        $this->assertCount(25, $page['staff']);
        $this->assertSame(31, $page['staffMeta']['total'], '30 teachers + the owner');
        $this->assertSame(2, $page['staffMeta']['last_page']);
    }

    public function test_searching_by_name_narrows_the_paginated_set(): void
    {
        $target = $this->makeUser('teacher', $this->branchA->id);
        $target->update(['name' => 'Searchable Zeleke']);
        for ($i = 1; $i <= 10; $i++) {
            $this->makeUser('teacher', $this->branchA->id);
        }

        $page = $this->props($this->actingAs($this->owner)->get('/staff?search=Zeleke')->assertOk());

        $this->assertSame(1, $page['staffMeta']['total']);
        $this->assertSame($target->id, $page['staff'][0]['id']);
    }

    public function test_searching_by_phone_fragment_matches_however_it_is_typed(): void
    {
        $target = $this->makeUser('teacher', $this->branchA->id);
        $target->update(['phone' => '+251911223344']);

        $page = $this->props($this->actingAs($this->owner)->get('/staff?search=0911 22 33')->assertOk());

        $this->assertSame([$target->id], collect($page['staff'])->pluck('id')->all());
    }

    public function test_the_export_is_never_limited_to_one_page(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->makeUser('teacher', $this->branchA->id);
        }

        Excel::fake();
        $this->actingAs($this->owner)->get('/staff/export');
        Excel::assertDownloaded('staff.xlsx', function ($export) {
            // 1 school-name row + 1 title row + 1 header row + 31 staff rows (the owner + 30 teachers)
            $this->assertCount(34, $export->array());

            return true;
        });
    }

    public function test_the_export_respects_the_same_search_as_the_screen(): void
    {
        $target = $this->makeUser('teacher', $this->branchA->id);
        $target->update(['name' => 'Searchable Zeleke']);
        $this->makeUser('teacher', $this->branchA->id);

        Excel::fake();
        $this->actingAs($this->owner)->get('/staff/export?search=Zeleke');
        Excel::assertDownloaded('staff.xlsx', function ($export) use ($target) {
            $dataRows = array_slice($export->array(), 3);
            $this->assertCount(1, $dataRows);
            $this->assertSame($target->name, $dataRows[0][0]);

            return true;
        });
    }

    public function test_the_number_of_queries_does_not_grow_once_the_page_is_already_full(): void
    {
        // the page is always capped at one page's worth of rows, so the meaningful comparison is between two
        // already-full pages, not a half-empty one versus a full one (which naturally costs a little less)
        for ($i = 1; $i <= 24; $i++) {
            $this->makeUser('teacher', $this->branchA->id);
        }
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/staff')->assertOk();
        $fewQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($i = 1; $i <= 40; $i++) {
            $this->makeUser('teacher', $this->branchA->id);
        }
        DB::flushQueryLog();

        $this->actingAs($this->owner)->get('/staff')->assertOk();
        $manyQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($fewQueries + 2, $manyQueries);
    }

    public function test_search_still_respects_branch_scope(): void
    {
        $target = $this->makeUser('teacher', $this->branchB->id);
        $target->update(['name' => 'Eastonly Zeleke']);
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-staff']);

        $page = $this->props($this->actingAs($registrar)->get('/staff?search=Eastonly')->assertOk());

        $this->assertSame(0, $page['staffMeta']['total']);
    }
}
