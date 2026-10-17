<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Notifications\CalendarEventPublished;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Academic expansion, Phase 3: School Calendar. Deliberately mirrors `Announcement`'s own shape (the exact
 * same 3-bucket audience, branch-or-every-branch scoping, one-shot notify guard) -- confirmed with the user
 * as the right granularity for "role-based visibility" rather than a finer per-role picker. Unlike
 * Announcement, there is no `publish_on` scheduling: an event notifies its audience the moment it is
 * created, since `start_date` already answers "when it happens."
 */
class CalendarEventTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mk(array $o): CalendarEvent
    {
        return CalendarEvent::create($o + [
            'school_id' => $this->school->id, 'created_by' => $this->owner->id,
            'event_type' => 'other', 'start_date' => '2026-10-20',
        ]);
    }

    public function test_events_reach_the_right_audience_and_branch(): void
    {
        $parentA = $this->makeParent($this->studentA);
        $parentB = $this->makeParent($this->studentB);
        $teacherA = $this->makeUser('teacher', $this->branchA->id);
        $teacherB = $this->makeUser('teacher', $this->branchB->id);

        $this->mk(['title' => 'All', 'audience' => 'everyone', 'branch_id' => null]);
        $this->mk(['title' => 'Parents everywhere', 'audience' => 'parents', 'branch_id' => null]);
        $this->mk(['title' => 'Staff everywhere', 'audience' => 'staff', 'branch_id' => null]);
        $this->mk(['title' => 'East parents', 'audience' => 'parents', 'branch_id' => $this->branchB->id]);
        $this->mk(['title' => 'Main everyone', 'audience' => 'everyone', 'branch_id' => $this->branchA->id]);

        $titles = fn ($u) => collect($this->props($this->actingAs($u)->get('/calendar'))['upcoming'])->pluck('title')->sort()->values()->all();

        $this->assertSame(['All', 'Main everyone', 'Parents everywhere'], $titles($parentA));
        $this->assertSame(['All', 'East parents', 'Parents everywhere'], $titles($parentB));
        $this->assertSame(['All', 'Main everyone', 'Staff everywhere'], $titles($teacherA));
        $this->assertSame(['All', 'Staff everywhere'], $titles($teacherB));

        $ownerTitles = $titles($this->owner);
        $this->assertCount(5, $ownerTitles, 'a manager sees every audience and branch in their scope');
    }

    public function test_only_managers_can_post_branch_staff_only_to_their_branch(): void
    {
        $data = ['title' => 'Picnic', 'event_type' => 'activity', 'audience' => 'everyone', 'branch_id' => '', 'start_date' => '2026-10-20'];

        $this->actingAs($this->makeUser('teacher', $this->branchA->id))->post('/calendar', $data)->assertForbidden();
        $this->actingAs($this->makeParent($this->studentA))->post('/calendar', $data)->assertForbidden();

        $this->actingAs($this->makeUser('branch_admin', $this->branchA->id))->post('/calendar', $data)->assertSessionHasNoErrors();
        $this->assertSame($this->branchA->id, CalendarEvent::firstOrFail()->branch_id, 'forced to their own branch even when asking for school-wide');

        $this->actingAs($this->owner)->post('/calendar', ['branch_id' => ''] + $data)->assertSessionHasNoErrors();
        $this->assertNull(CalendarEvent::latest('id')->first()->branch_id);
    }

    public function test_validation_refuses_a_missing_title_bad_type_and_an_end_before_start(): void
    {
        $base = ['event_type' => 'meeting', 'audience' => 'everyone', 'branch_id' => '', 'start_date' => '2026-10-20'];

        $this->actingAs($this->owner)->post('/calendar', ['title' => ''] + $base)->assertSessionHasErrors('title');
        $this->actingAs($this->owner)->post('/calendar', ['title' => 'x', 'event_type' => 'not-a-type'] + $base)->assertSessionHasErrors('event_type');
        $this->actingAs($this->owner)->post('/calendar', ['title' => 'x', 'end_date' => '2026-10-19'] + $base)->assertSessionHasErrors('end_date');
        $this->assertSame(0, CalendarEvent::count());
    }

    public function test_creating_an_event_notifies_its_audience_immediately(): void
    {
        $parentA = $this->makeParent($this->studentA);
        $teacherA = $this->makeUser('teacher', $this->branchA->id);

        $this->actingAs($this->owner)->post('/calendar', [
            'title' => 'Sports day', 'event_type' => 'activity', 'audience' => 'parents', 'branch_id' => '', 'start_date' => '2026-10-20',
        ])->assertSessionHasNoErrors();

        $event = CalendarEvent::firstOrFail();
        $this->assertNotNull($event->notified_at, 'notified immediately, no scheduling concept here');
        $this->assertCount(1, $parentA->notifications);
        $this->assertSame(CalendarEventPublished::class, $parentA->notifications->first()->type);
        $this->assertStringContainsString('Sports day', $parentA->notifications->first()->data['body']);
        $this->assertSame($event->id, $parentA->notifications->first()->data['calendar_event_id']);
        $this->assertCount(0, $teacherA->notifications, 'staff are not in the parents audience');
    }

    public function test_a_branch_admin_cannot_change_or_remove_another_branchs_event(): void
    {
        $this->mk(['title' => 'East only', 'audience' => 'everyone', 'branch_id' => $this->branchB->id]);
        $event = CalendarEvent::firstOrFail();
        $branchAAdmin = $this->makeUser('branch_admin', $this->branchA->id);

        $this->actingAs($branchAAdmin)->put("/calendar/{$event->id}", [
            'title' => 'Hijacked', 'event_type' => 'other', 'audience' => 'everyone', 'start_date' => '2026-10-20',
        ])->assertForbidden();
        $this->actingAs($branchAAdmin)->delete("/calendar/{$event->id}")->assertForbidden();
    }

    public function test_removing_an_event_archives_it_and_is_audited(): void
    {
        $this->mk(['title' => 'Temp', 'audience' => 'everyone', 'branch_id' => null]);
        $event = CalendarEvent::firstOrFail();

        $this->actingAs($this->owner)->delete("/calendar/{$event->id}")->assertSessionHasNoErrors();

        $this->assertNull(CalendarEvent::find($event->id));
        $this->assertNotNull(CalendarEvent::withTrashed()->find($event->id), 'archived, not erased');
        $this->assertNotNull(AuditLog::where('action', 'calendar_event.archived')->first());
    }

    public function test_someone_with_no_manage_permission_can_still_view_but_never_write(): void
    {
        $teacher = $this->makeUser('teacher', $this->branchA->id);
        $this->mk(['title' => 'Visible', 'audience' => 'everyone', 'branch_id' => null]);

        $this->actingAs($teacher)->get('/calendar')->assertOk();
        $this->actingAs($teacher)->post('/calendar', [
            'title' => 'x', 'event_type' => 'other', 'audience' => 'everyone', 'start_date' => '2026-10-20',
        ])->assertForbidden();
    }

    public function test_past_and_upcoming_events_are_split_correctly(): void
    {
        $this->mk(['title' => 'Yesterday', 'audience' => 'everyone', 'branch_id' => null, 'start_date' => '2026-10-14']);
        $this->mk(['title' => 'Today', 'audience' => 'everyone', 'branch_id' => null, 'start_date' => '2026-10-15']);
        $this->mk(['title' => 'Multi-day spanning today', 'audience' => 'everyone', 'branch_id' => null, 'start_date' => '2026-10-10', 'end_date' => '2026-10-16']);
        $this->mk(['title' => 'Next week', 'audience' => 'everyone', 'branch_id' => null, 'start_date' => '2026-10-22']);

        $p = $this->props($this->actingAs($this->owner)->get('/calendar'));

        $this->assertSame(['Multi-day spanning today', 'Today', 'Next week'], collect($p['upcoming'])->pluck('title')->all());
        $this->assertSame(['Yesterday'], collect($p['past'])->pluck('title')->all());
    }
}
