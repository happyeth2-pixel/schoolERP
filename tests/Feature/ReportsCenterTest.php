<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Production-readiness audit finding: every report/export already worked but had no single index
 * page -- a user had to already know its URL. `ReportsController` is a pure read-only aggregator;
 * it must never show a report the viewer's own permission would otherwise refuse, and must never
 * hide one they genuinely hold.
 */
class ReportsCenterTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    public function test_an_owner_sees_every_report_group(): void
    {
        $page = $this->props($this->actingAs($this->owner)->get('/reports')->assertOk());

        $keys = collect($page['groups'])->pluck('key');
        $this->assertTrue($keys->contains('accounting'));
        $this->assertTrue($keys->contains('oversight'));

        $accounting = collect($page['groups'])->firstWhere('key', 'accounting');
        $this->assertCount(8, $accounting['items'], 'all 8 accounting reports should be listed for an owner');
    }

    public function test_a_teacher_with_no_reporting_permission_sees_an_empty_explained_page(): void
    {
        // the page itself stays reachable (no abort) -- a graceful "nothing for you here, ask your
        // administrator" empty state is friendlier than a 403, and every group is still correctly
        // filtered out server-side, so nothing is actually disclosed.
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);

        $page = $this->props($this->actingAs($teacher)->get('/reports')->assertOk());

        $this->assertSame([], $page['groups']);
    }

    public function test_an_accountant_sees_only_what_their_permissions_actually_allow(): void
    {
        $accountant = $this->makeUser('accountant', $this->branchA->id, ['view-financial-reports']);

        $page = $this->props($this->actingAs($accountant)->get('/reports')->assertOk());
        $keys = collect($page['groups'])->pluck('key');

        $this->assertTrue($keys->contains('finance'));
        $this->assertFalse($keys->contains('accounting'), 'view-financial-reports does not grant the accounting-engine reports');
        $this->assertFalse($keys->contains('oversight'), 'no view-audit-log permission means no audit log entry');
        $this->assertFalse($keys->contains('people'), 'no manage-students/manage-staff means no people exports');
    }

    public function test_every_listed_url_is_genuinely_reachable_by_that_same_user(): void
    {
        $page = $this->props($this->actingAs($this->owner)->get('/reports')->assertOk());

        foreach ($page['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $response = $this->actingAs($this->owner)->get($item['url']);
                $this->assertContains($response->getStatusCode(), [200, 302], "report '{$item['key']}' listed for the owner must actually open, got {$response->getStatusCode()}");
            }
        }
    }
}
