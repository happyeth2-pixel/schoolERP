<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The basics of the sign-in screen. Phone numbers, temporary passwords and resets are covered in PhoneLoginTest. */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_the_login_screen_asks_for_a_phone_number_not_an_email(): void
    {
        $page = $this->get('/login')->original->getData()['page'];

        $this->assertSame('Auth/Login', $page['component']);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create(['phone' => '0911223344', 'login_phone' => '0911223344']);

        $response = $this->post('/login', [
            'phone' => '0911 22 33 44',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        User::factory()->create(['phone' => '0911223344', 'login_phone' => '0911223344']);

        $this->post('/login', [
            'phone' => '0911223344',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
