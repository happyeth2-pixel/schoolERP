<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The UI-kit gallery (/dev/ui-kit) is for developers and designers: it must work while developing and must
 * not exist at all in production, where it would just be one more page that is not part of the product.
 */
class UiKitRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->get('/dev/ui-kit')->assertRedirect('/login');
    }

    public function test_a_signed_in_user_can_open_it_outside_production(): void
    {
        $school = School::create(['name' => 'Kit School', 'code' => 'KIT']);
        $user = User::create(['school_id' => $school->id, 'name' => 'Dev', 'email' => 'dev@kit.test', 'password' => 'password', 'status' => 'active', 'email_verified_at' => now()]);

        $this->actingAs($user)->get('/dev/ui-kit')->assertOk();
    }

    public function test_the_route_is_not_registered_when_the_app_boots_in_production(): void
    {
        $previous = [$_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null, getenv('APP_ENV')];

        try {
            putenv('APP_ENV=production');
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
            $this->refreshApplication();

            $this->assertTrue(app()->environment('production'));
            $this->assertFalse(app('router')->has('dev.ui-kit'), 'the UI kit route must not exist in production');
        } finally {
            [$env, $server, $getenv] = $previous;
            $env === null ? array_key_exists('APP_ENV', $_ENV) && $_ENV['APP_ENV'] = 'testing' : $_ENV['APP_ENV'] = $env;
            $server === null ? array_key_exists('APP_ENV', $_SERVER) && $_SERVER['APP_ENV'] = 'testing' : $_SERVER['APP_ENV'] = $server;
            putenv('APP_ENV='.($getenv === false ? 'testing' : $getenv));
            $this->refreshApplication();
        }
    }
}
