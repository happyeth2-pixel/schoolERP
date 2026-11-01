<?php

namespace Tests\Feature;

use App\Models\SiteAnnouncement;
use App\Support\SiteContentSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class SiteContentAndAnnouncementsTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    public function test_the_home_page_shows_default_copy_when_nothing_has_been_customized(): void
    {
        $this->buildSchool();

        $props = $this->props($this->get('/'));

        $this->assertSame(SiteContentSchema::pages()['home']['hero_title']['default'], $props['content']['hero_title']);
    }

    public function test_an_owner_can_change_website_text_and_it_appears_on_the_public_page(): void
    {
        $this->buildSchool();

        $payload = array_merge(
            array_map(fn ($f) => $f['default'], SiteContentSchema::pages()['home']),
            ['hero_title' => 'A Brand New Headline'],
        );
        $this->actingAs($this->owner)->patch(route('site-content.update', 'home'), $payload)
            ->assertSessionHasNoErrors();

        $props = $this->props($this->get('/'));
        $this->assertSame('A Brand New Headline', $props['content']['hero_title']);
    }

    public function test_someone_without_permission_cannot_edit_website_text(): void
    {
        $this->buildSchool();
        $teacher = $this->makeUser('teacher', $this->branchA->id);

        $this->actingAs($teacher)->get(route('site-content.edit', 'home'))->assertForbidden();
    }

    public function test_an_unknown_page_name_is_rejected(): void
    {
        $this->buildSchool();

        $this->actingAs($this->owner)->get(route('site-content.edit', 'nonsense'))->assertNotFound();
    }

    public function test_an_active_in_range_announcement_shows_on_every_public_page(): void
    {
        $this->buildSchool();
        SiteAnnouncement::create(['message' => 'School closed for the holiday', 'is_active' => true, 'created_by' => $this->owner->id]);

        foreach (['/', '/about', '/contact'] as $url) {
            $props = $this->props($this->get($url));
            $this->assertSame('School closed for the holiday', $props['announcement']);
        }
    }

    public function test_an_inactive_announcement_is_not_shown(): void
    {
        $this->buildSchool();
        SiteAnnouncement::create(['message' => 'Hidden', 'is_active' => false]);

        $props = $this->props($this->get('/'));
        $this->assertNull($props['announcement']);
    }

    public function test_an_announcement_outside_its_date_range_is_not_shown(): void
    {
        $this->buildSchool();
        SiteAnnouncement::create(['message' => 'Future event', 'is_active' => true, 'starts_on' => now()->addMonth()->toDateString()]);
        SiteAnnouncement::create(['message' => 'Past event', 'is_active' => true, 'ends_on' => now()->subMonth()->toDateString()]);

        $props = $this->props($this->get('/'));
        $this->assertNull($props['announcement']);
    }

    public function test_the_owner_can_toggle_and_delete_an_announcement(): void
    {
        $this->buildSchool();
        $announcement = SiteAnnouncement::create(['message' => 'Test', 'is_active' => true]);

        $this->actingAs($this->owner)->post(route('site-announcements.toggle', $announcement))->assertRedirect();
        $this->assertFalse($announcement->fresh()->is_active);

        $this->actingAs($this->owner)->delete(route('site-announcements.destroy', $announcement))->assertRedirect();
        $this->assertModelMissing($announcement);
    }

    public function test_someone_without_permission_cannot_manage_announcements(): void
    {
        $this->buildSchool();
        $teacher = $this->makeUser('teacher', $this->branchA->id);

        $this->actingAs($teacher)->get(route('site-announcements.index'))->assertForbidden();
        $this->actingAs($teacher)->post(route('site-announcements.store'), ['message' => 'x'])->assertForbidden();
    }
}
