<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Production-readiness audit finding: this app is 100% Inertia, but a 403/404/419/500 previously
 * fell through to Laravel's own bare default error view instead of this product's plain-language
 * design. `bootstrap/app.php` now renders a shared `Error` Inertia page for these statuses --
 * skipped when APP_DEBUG is on, so local development still sees the real exception.
 */
class FriendlyErrorPagesTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    public function test_a_route_that_does_not_exist_renders_the_friendly_error_page(): void
    {
        config(['app.debug' => false]); // a real production deployment always has this off

        $response = $this->get('/this-route-does-not-exist-anywhere');

        $response->assertNotFound();
        $response->assertInertia(fn ($page) => $page->component('Error')->where('status', 404));
    }

    public function test_a_forbidden_action_renders_the_friendly_error_page(): void
    {
        config(['app.debug' => false]);
        $this->buildSchool();
        $teacher = $this->makeUser('teacher', $this->branchA->id, []);

        // a teacher holds no manage-staff permission at all
        $response = $this->actingAs($teacher)->get('/staff');

        $response->assertForbidden();
        $response->assertInertia(fn ($page) => $page->component('Error')->where('status', 403));
    }

    public function test_the_friendly_page_is_skipped_when_debug_mode_is_on(): void
    {
        config(['app.debug' => true]);

        $response = $this->get('/this-route-does-not-exist-anywhere');

        $response->assertNotFound();
        // Laravel's own raw debug error view, not this app's Inertia page -- confirmed by the
        // ABSENCE of the Inertia response header this product's own Error page would always carry.
        $response->assertHeaderMissing('X-Inertia');
    }
}
