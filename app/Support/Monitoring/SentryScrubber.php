<?php

namespace App\Support\Monitoring;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * The `before_send` callback for every event Sentry would otherwise transmit: the last
 * checkpoint before anything leaves this server. Nothing here is optional or configurable
 * per-environment -- it runs the same way whether SENTRY_LARAVEL_DSN happens to be set on a
 * dev box or in production, because the safest scrubbing is the kind that can't be forgotten.
 *
 * What this deliberately keeps OUT of every event, however it might otherwise have gotten in
 * (request body, query string, breadcrumbs, extra context, tags):
 *   - authentication material: passwords, tokens, secrets, passphrases, session/auth headers, cookies
 *   - personal information: names, phone numbers, email addresses, physical addresses, date of
 *     birth, emergency contacts
 *   - payment/financial detail: bank account numbers/names, payment references, receipt numbers,
 *     uploaded proof file content
 * What it keeps IN (because incidents are hard to investigate without it): the exception itself,
 * its stack trace, the route/URL shape (not query values), the HTTP method and status, timing,
 * and a PSEUDONYMOUS actor reference (see attachSafeUserContext()) -- enough to say "this broke
 * for an accountant in branch 4" without saying who, by name.
 */
class SentryScrubber
{
    /**
     * Matched case-insensitively as a SUBSTRING of any key, at any depth, in request data,
     * breadcrumbs and extra context. Deliberately broad (better to over-scrub an oddly-named
     * harmless field than under-scrub a real one).
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'passphrase', 'secret', 'token', 'authorization', 'api_key', 'apikey',
        'phone', 'email', 'address', 'emergency_contact', 'date_of_birth', 'dob',
        'father_name', 'mother_name', 'grandfather_name', 'first_name', 'guardian_name', 'guardian_email', 'guardian_phone',
        'account_number', 'account_name', 'reference_number', 'receipt_number', 'proof',
        'ssn', 'national_id', 'reason', // discount/withdrawal/rejection/reopen reasons are free text people write -- treated as PII by default
    ];

    private const NEVER_SENT_REQUEST_KEYS = ['cookies'];

    private const NEVER_SENT_HEADERS = ['authorization', 'cookie', 'x-xsrf-token'];

    private const FILTERED = '[Filtered]';

    public static function scrub(Event $event, ?EventHint $hint = null): ?Event
    {
        self::scrubRequest($event);
        self::scrubUser($event);
        self::scrubBreadcrumbs($event);
        self::scrubExtra($event);

        return $event;
    }

    private static function scrubRequest(Event $event): void
    {
        $request = $event->getRequest();
        if ($request === []) {
            return;
        }

        foreach (self::NEVER_SENT_REQUEST_KEYS as $key) {
            unset($request[$key]);
        }

        if (isset($request['headers']) && is_array($request['headers'])) {
            foreach (array_keys($request['headers']) as $name) {
                if (in_array(strtolower($name), self::NEVER_SENT_HEADERS, true)) {
                    unset($request['headers'][$name]);
                }
            }
        }

        if (isset($request['data'])) {
            $request['data'] = self::scrubValue($request['data']);
        }

        // query_string arrives as a raw string ("search=Almaz&phone=0911..."), not a key/value
        // array -- scrubValue() only walks arrays, so a raw string would sail through untouched.
        // A confirmed-by-drill leak (see MONITORING.md): dropped wholesale rather than parsed
        // key-by-key, because a value can be sensitive even under an innocuous-looking key.
        if (isset($request['query_string']) && $request['query_string'] !== '') {
            $request['query_string'] = self::FILTERED;
        }

        // The URL embeds that same query string -- strip it back to just scheme/host/path.
        if (isset($request['url'])) {
            $request['url'] = strtok($request['url'], '?');
        }

        $event->setRequest($request);
    }

    /**
     * `send_default_pii` stays false, so the SDK does not attach the user's email/IP on its own --
     * but if anything upstream ever DOES set a user context (a future integration, a manual
     * Sentry::setUser() call someone adds later), this still strips it down to only what
     * ScopeIdentity::attach() puts there: a pseudonymous id, role and school/branch. Defense in
     * depth, not the primary mechanism.
     */
    private static function scrubUser(Event $event): void
    {
        $user = $event->getUser();
        if ($user === null) {
            return;
        }

        $safe = new UserDataBag($user->getId());
        foreach ($user->getMetadata() as $key => $value) {
            if (! self::looksSensitive($key)) {
                $safe->setMetadata($key, $value);
            }
        }
        $event->setUser($safe);
    }

    private static function scrubBreadcrumbs(Event $event): void
    {
        $breadcrumbs = $event->getBreadcrumbs();
        if ($breadcrumbs === []) {
            return;
        }

        // Breadcrumb is immutable, so a scrubbed one means building a new one, not mutating in place.
        $event->setBreadcrumb(array_map(fn ($b) => new Breadcrumb(
            $b->getLevel(), $b->getType(), $b->getCategory(), $b->getMessage(),
            self::scrubValue($b->getMetadata()), $b->getTimestamp(),
        ), $breadcrumbs));
    }

    private static function scrubExtra(Event $event): void
    {
        $extra = $event->getExtra();
        if ($extra !== []) {
            $event->setExtra(self::scrubValue($extra));
        }

        foreach ($event->getContexts() as $name => $data) {
            if (in_array($name, ['runtime', 'os', 'trace'], true)) {
                continue; // not user data, nothing to scrub
            }
            if (is_array($data)) {
                $event->setContext($name, self::scrubValue($data));
            }
        }
    }

    /** Recursively walks arrays; replaces the value of any key matching a sensitive fragment. */
    private static function scrubValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $v) {
            $out[$key] = (is_string($key) && self::looksSensitive($key)) ? self::FILTERED : self::scrubValue($v);
        }

        return $out;
    }

    private static function looksSensitive(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
