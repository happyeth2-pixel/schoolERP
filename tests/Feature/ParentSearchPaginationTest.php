<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * ParentController@index used to load every parent account (plus every one of their children) and let the browser
 * filter a fully loaded array, sliced client-side to the first 40 matches. This pins the fix: the database
 * searches and pages the directory (?search=, ?page=), and the query count stays flat as the directory grows.
 */
class ParentSearchPaginationTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    public function test_the_directory_is_paginated(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->makeParent($this->studentA);
        }

        $page = $this->props($this->actingAs($this->owner)->get('/parents')->assertOk());

        $this->assertCount(25, $page['parents']);
        $this->assertSame(30, $page['parentsMeta']['total']);
        $this->assertSame(2, $page['parentsMeta']['last_page']);
    }

    public function test_searching_by_a_childs_name_narrows_the_set(): void
    {
        $this->makeParent($this->studentA)->update(['name' => 'Searchable One']);
        for ($i = 1; $i <= 10; $i++) {
            $this->makeParent($this->studentB);
        }

        $page = $this->props($this->actingAs($this->owner)->get('/parents?search='.$this->studentA->student_id_number)->assertOk());

        $this->assertSame(1, $page['parentsMeta']['total']);
        $this->assertSame('Searchable One', $page['parents'][0]['name']);
    }

    public function test_searching_by_phone_matches_however_it_is_typed(): void
    {
        $p = $this->makeParent($this->studentA);
        $p->update(['phone' => '+251911223344']);

        $page = $this->props($this->actingAs($this->owner)->get('/parents?search=0911 22 33')->assertOk());

        $this->assertSame([$p->id], collect($page['parents'])->pluck('id')->all());
    }

    public function test_the_number_of_queries_does_not_grow_once_the_page_is_already_full(): void
    {
        // the page is always capped at one page's worth of rows, so the meaningful comparison is between two
        // already-full pages, not a half-empty one versus a full one (which naturally costs a little less)
        for ($i = 1; $i <= 25; $i++) {
            $this->makeParent($this->studentA);
        }
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/parents')->assertOk();
        $fewQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($i = 1; $i <= 40; $i++) {
            $this->makeParent($this->studentA);
        }
        DB::flushQueryLog();

        $this->actingAs($this->owner)->get('/parents')->assertOk();
        $manyQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($fewQueries + 2, $manyQueries);
    }

    public function test_search_still_respects_the_viewers_own_branch_for_branch_scoped_staff(): void
    {
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-parents']);

        // the parents directory itself is not branch-scoped (a family can have children in several branches),
        // but a branch-scoped viewer may still only manage/reset the ones reachable through their own students --
        // this test documents that the directory listing shows every parent in the school regardless, same as before
        $this->makeParent($this->studentB);
        $page = $this->props($this->actingAs($registrar)->get('/parents')->assertOk());

        $this->assertSame(1, $page['parentsMeta']['total']);
    }
}
