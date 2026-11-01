<?php

namespace Tests\Feature;

use App\Support\RealtimeBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Every one of the app's Notification classes writes a `database` row; AppServiceProvider::boot() hooks
 * that row's `created` event to push it over Reverb in real time. These tests are about what happens when
 * Reverb cannot be reached -- the one thing that must never happen is the real action (and the database
 * row itself) failing because of it. See RealtimeBroadcast and config/broadcasting.php's client_options.
 */
class RealtimeBroadcastTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    public function test_a_notification_is_still_saved_even_when_the_real_time_push_cannot_reach_anything(): void
    {
        // An address nothing is listening on, so the push genuinely fails the way a down Reverb would --
        // not mocked away, the real HTTP attempt really happens and really fails.
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => '127.0.0.1']);
        config(['broadcasting.connections.reverb.options.port' => 18080]);
        config(['broadcasting.connections.reverb.options.scheme' => 'http']);
        config(['broadcasting.connections.reverb.options.useTLS' => false]);
        config(['broadcasting.connections.reverb.client_options.timeout' => 1]);
        config(['broadcasting.connections.reverb.client_options.connect_timeout' => 1]);

        Log::shouldReceive('warning')->once();

        $notification = DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\StudentMarkedAbsent',
            'notifiable_type' => \App\Models\User::class,
            'notifiable_id' => $this->owner->id,
            'data' => ['title' => 'Test', 'body' => 'Test body'],
        ]);

        $this->assertNotNull(DatabaseNotification::find($notification->id), 'the row survives regardless of whether the push could be delivered');
        $this->assertTrue(RealtimeBroadcast::isDown(), 'the failure trips the breaker');
    }

    public function test_the_circuit_breaker_skips_the_network_call_entirely_once_tripped(): void
    {
        RealtimeBroadcast::markDown();
        $this->assertTrue(RealtimeBroadcast::isDown());

        // While down, creating a notification must not even attempt the network call -- nothing to
        // assert about timing here (that's the whole point), just that it stays quiet and fast.
        Log::shouldReceive('warning')->never();

        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\StudentMarkedAbsent',
            'notifiable_type' => \App\Models\User::class,
            'notifiable_id' => $this->owner->id,
            'data' => ['title' => 'Test', 'body' => 'Test body'],
        ]);
    }

    public function test_the_breaker_resets_itself_after_its_window_so_a_recovered_reverb_is_noticed(): void
    {
        RealtimeBroadcast::markDown();
        $this->assertTrue(RealtimeBroadcast::isDown());

        Carbon::setTestNow(now()->addSeconds(25));
        $this->assertFalse(RealtimeBroadcast::isDown(), 'nobody has to remember to clear this by hand');
        Carbon::setTestNow();
    }

    public function test_a_notification_for_a_non_user_notifiable_is_never_pushed(): void
    {
        // Every notification in this app goes to a User (see each class's own via(), all ['database']),
        // but the hook itself must not assume that -- a stray notifiable of another type must be a no-op,
        // not an attempt to open a channel for a model that was never meant to receive one.
        Log::shouldReceive('warning')->never();

        $notification = DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\StudentMarkedAbsent',
            'notifiable_type' => \App\Models\Student::class,
            'notifiable_id' => $this->studentA->id,
            'data' => ['title' => 'Test', 'body' => 'Test body'],
        ]);

        $this->assertNotNull(DatabaseNotification::find($notification->id));
    }
}
