<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Production-readiness audit finding: `config('app.force_https')` already auto-derives from
 * `APP_ENV=production` so a deployment is HTTPS-safe even if nobody sets FORCE_HTTPS explicitly --
 * but `config('session.secure')` had no such fallback, so a production deployment that forgot to
 * set SESSION_SECURE_COOKIE sent session cookies without the Secure flag despite HTTPS being
 * enforced elsewhere. `config/session.php` also calls storage_path() for its file-driver path, so
 * it needs a real booted app (hence extending the Laravel TestCase, not a bare PHPUnit one) -- the
 * file is re-`include`d (not `require`d) under each faked APP_ENV so its env() calls re-evaluate.
 */
class SessionCookieSecurityTest extends TestCase
{
    private function secureFor(?string $appEnv, ?string $secureCookie): mixed
    {
        $prevAppEnv = $_ENV['APP_ENV'] ?? null;
        $prevSecure = $_ENV['SESSION_SECURE_COOKIE'] ?? null;

        try {
            if ($appEnv === null) {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            } else {
                putenv("APP_ENV={$appEnv}");
                $_ENV['APP_ENV'] = $appEnv;
            }

            $secureCookie === null ? putenv('SESSION_SECURE_COOKIE') : putenv("SESSION_SECURE_COOKIE={$secureCookie}");
            if ($secureCookie === null) {
                unset($_ENV['SESSION_SECURE_COOKIE']);
            } else {
                $_ENV['SESSION_SECURE_COOKIE'] = $secureCookie;
            }

            $config = include base_path('config/session.php');

            return $config['secure'];
        } finally {
            if ($prevAppEnv === null) {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            } else {
                putenv("APP_ENV={$prevAppEnv}");
                $_ENV['APP_ENV'] = $prevAppEnv;
            }
            if ($prevSecure === null) {
                putenv('SESSION_SECURE_COOKIE');
                unset($_ENV['SESSION_SECURE_COOKIE']);
            } else {
                putenv("SESSION_SECURE_COOKIE={$prevSecure}");
                $_ENV['SESSION_SECURE_COOKIE'] = $prevSecure;
            }
        }
    }

    public function test_production_defaults_the_cookie_to_secure_even_if_unset(): void
    {
        $this->assertTrue($this->secureFor('production', null), 'a production deployment must default to a Secure session cookie');
    }

    public function test_non_production_defaults_the_cookie_to_insecure_for_easy_local_http_dev(): void
    {
        $this->assertNotTrue($this->secureFor('local', null));
    }

    public function test_an_explicit_env_value_still_overrides_the_default_either_way(): void
    {
        $this->assertFalse($this->secureFor('production', 'false'), 'an operator can still explicitly opt out');
        $this->assertTrue($this->secureFor('local', 'true'), 'an operator can still explicitly opt in');
    }
}
