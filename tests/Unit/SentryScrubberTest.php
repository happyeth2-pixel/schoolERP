<?php

namespace Tests\Unit;

use App\Support\Monitoring\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\UserDataBag;
use Tests\TestCase;

/** Proves exactly what does and does not survive the trip to Sentry -- see the class docblock for the policy this enforces. */
class SentryScrubberTest extends TestCase
{
    public function test_authentication_and_secret_fields_are_stripped_from_request_data(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'method' => 'POST',
            'data' => ['password' => 'hunter2', 'password_confirmation' => 'hunter2', 'api_token' => 'abc123', 'ordinary_field' => 'keep me'],
        ]);

        $scrubbed = SentryScrubber::scrub($event)->getRequest();

        $this->assertSame('[Filtered]', $scrubbed['data']['password']);
        $this->assertSame('[Filtered]', $scrubbed['data']['password_confirmation']);
        $this->assertSame('[Filtered]', $scrubbed['data']['api_token']);
        $this->assertSame('keep me', $scrubbed['data']['ordinary_field']);
    }

    public function test_personal_information_is_stripped_wherever_it_appears(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['data' => [
            'first_name' => 'Almaz', 'father_name' => 'Tesfaye', 'phone' => '+251911223344',
            'email' => 'parent@example.com', 'address' => 'Bole', 'date_of_birth' => '2015-05-01',
            'emergency_contact' => '+251922334455', 'status' => 'active',
        ]]);

        $data = SentryScrubber::scrub($event)->getRequest()['data'];

        foreach (['first_name', 'father_name', 'phone', 'email', 'address', 'date_of_birth', 'emergency_contact'] as $key) {
            $this->assertSame('[Filtered]', $data[$key], "expected {$key} to be filtered");
        }
        $this->assertSame('active', $data['status'], 'an ordinary non-personal field is left alone');
    }

    public function test_payment_and_banking_detail_is_stripped(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['data' => [
            'account_number' => '1000234455', 'account_name' => 'Almaz Tesfaye', 'reference_number' => 'FT2609231234',
            'receipt_number' => 'RCT-2026-000042', 'proof' => 'binary-file-data-here', 'amount' => '500.00',
        ]]);

        $data = SentryScrubber::scrub($event)->getRequest()['data'];

        foreach (['account_number', 'account_name', 'reference_number', 'receipt_number', 'proof'] as $key) {
            $this->assertSame('[Filtered]', $data[$key]);
        }
        $this->assertSame('500.00', $data['amount'], 'the amount itself is not personal/secret and is kept for debugging');
    }

    public function test_scrubbing_is_recursive_and_matches_keys_case_insensitively(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['data' => [
            'guardian' => ['GUARDIAN_EMAIL' => 'x@y.test', 'guardian_relationship' => 'mother'],
        ]]);

        $data = SentryScrubber::scrub($event)->getRequest()['data'];

        $this->assertSame('[Filtered]', $data['guardian']['GUARDIAN_EMAIL']);
        $this->assertSame('mother', $data['guardian']['guardian_relationship'], 'a field that only shares part of a sensitive word is not over-matched');
    }

    public function test_cookies_and_auth_headers_are_removed_entirely_not_just_filtered(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'cookies' => ['laravel_session' => 'abc123'],
            'headers' => ['Authorization' => 'Bearer abc', 'Cookie' => 'x=1', 'X-Xsrf-Token' => 'y', 'Accept' => 'application/json'],
        ]);

        $request = SentryScrubber::scrub($event)->getRequest();

        $this->assertArrayNotHasKey('cookies', $request);
        $this->assertArrayNotHasKey('Authorization', $request['headers']);
        $this->assertArrayNotHasKey('Cookie', $request['headers']);
        $this->assertArrayNotHasKey('X-Xsrf-Token', $request['headers']);
        $this->assertSame('application/json', $request['headers']['Accept'], 'a harmless header is left alone');
    }

    public function test_a_user_context_is_reduced_to_only_the_pseudonymous_id_and_safe_metadata(): void
    {
        $event = Event::createEvent();
        $user = new UserDataBag('42', 'real@name.test', '203.0.113.5', 'Real Name');
        $user->setMetadata('role', 'accountant');
        $user->setMetadata('phone', '+251911223344');
        $event->setUser($user);

        $scrubbed = SentryScrubber::scrub($event)->getUser();

        $this->assertSame('42', $scrubbed->getId());
        $this->assertNull($scrubbed->getEmail(), 'email must never survive, however it got attached');
        $this->assertNull($scrubbed->getIpAddress());
        $this->assertNull($scrubbed->getUsername());
        $this->assertSame('accountant', $scrubbed->getMetadata()['role']);
        $this->assertArrayNotHasKey('phone', $scrubbed->getMetadata());
    }

    public function test_breadcrumb_metadata_is_scrubbed(): void
    {
        $event = Event::createEvent();
        $event->setBreadcrumb([
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'request', ['email' => 'x@y.test', 'status' => 200]),
        ]);

        $breadcrumb = SentryScrubber::scrub($event)->getBreadcrumbs()[0];

        $this->assertSame('[Filtered]', $breadcrumb->getMetadata()['email']);
        $this->assertSame(200, $breadcrumb->getMetadata()['status']);
    }

    public function test_extra_context_is_scrubbed(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['discount_reason' => 'Family financial hardship', 'invoice_id' => 42]);

        $extra = SentryScrubber::scrub($event)->getExtra();

        $this->assertSame('[Filtered]', $extra['discount_reason']);
        $this->assertSame(42, $extra['invoice_id']);
    }

    public function test_the_query_string_and_url_are_stripped_not_just_filtered_by_key(): void
    {
        // A live drill against a mock Sentry receiver caught this for real: query_string arrives as
        // a raw string, not a key/value array, so key-based scrubbing alone silently misses it.
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'http://localhost/students?search=Almaz+Tesfaye&guardian_phone=0911223344',
            'query_string' => 'search=Almaz+Tesfaye&guardian_phone=0911223344',
        ]);

        $request = SentryScrubber::scrub($event)->getRequest();

        $this->assertSame('http://localhost/students', $request['url'], 'the path is kept, the query string is not');
        $this->assertSame('[Filtered]', $request['query_string']);
    }

    public function test_an_event_with_no_request_or_user_is_left_alone_without_error(): void
    {
        $event = Event::createEvent();
        $result = SentryScrubber::scrub($event);

        $this->assertSame($event, $result);
        $this->assertSame([], $result->getRequest());
    }
}
