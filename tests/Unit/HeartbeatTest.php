<?php

namespace Tests\Unit;

use App\Support\Monitoring\Heartbeat;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    public function test_a_success_pings_the_plain_url(): void
    {
        Http::fake();

        Heartbeat::ping('https://hc-ping.com/abc123', success: true);

        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.com/abc123');
    }

    public function test_a_failure_pings_the_fail_suffixed_url(): void
    {
        Http::fake();

        Heartbeat::ping('https://hc-ping.com/abc123', success: false);

        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.com/abc123/fail');
    }

    public function test_the_fail_suffix_is_configurable(): void
    {
        Http::fake();
        config(['backup.heartbeat.fail_suffix' => '?status=fail']);

        Heartbeat::ping('https://example.test/ping/abc', success: false);

        Http::assertSent(fn ($request) => $request->url() === 'https://example.test/ping/abc?status=fail');
    }

    public function test_no_url_means_no_request_and_no_error(): void
    {
        Http::fake();

        Heartbeat::ping(null, success: true);
        Heartbeat::ping('', success: true);

        Http::assertNothingSent();
    }

    public function test_a_network_failure_pinging_is_swallowed_not_thrown(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('DNS failure'));

        Heartbeat::ping('https://hc-ping.com/abc123', success: true);

        $this->assertTrue(true, 'reaching this line means the ping failure did not propagate');
    }
}
