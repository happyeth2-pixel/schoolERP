<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This was stock Laravel Breeze scaffolding testing a public self-registration page -- but this
 * product deliberately has none (see routes/auth.php's own comment: every account must be
 * provisioned by an admin or created automatically when a student is enrolled, never a public
 * /register page that could create a school-less, role-less orphan account). The original tests
 * asserted the OPPOSITE of the real architecture and have failed since before this product's own
 * account model existed. Rewritten to assert the actual intended behavior instead of being deleted.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_public_registration_page(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_posting_to_register_creates_no_account_and_authenticates_nobody(): void
    {
        $before = User::count();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertSame($before, User::count(), 'posting to a route that does not exist must never create an orphan account');
    }
}
