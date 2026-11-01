<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Every named rate limiter in the app except sign-in itself, which already has its own deeper system
 * (config/login_security.php, App\Security\LoginThrottle). The thresholds live in config/rate_limits.php --
 * nothing here is a magic number, and every one of them can be tuned per deployment through .env without
 * touching code. See that file for what each category protects and why its number is what it is, and
 * RATE_LIMITING_AND_ABUSE_PROTECTION.md for the full policy table.
 *
 * The identification key, for every category below: the signed-in user's own id when there is one ("user:7"),
 * never the IP alone -- this is what lets a whole staff room share one school network without throttling each
 * other, while still stopping any ONE account from consuming more than its share. Costlier categories
 * (export/pdf/reports/bulk/upload) layer a second, much looser per-IP ceiling on top (see withIpBackstop()) as
 * a defense-in-depth catch for one actor spreading load across several accounts or an unauthenticated angle on
 * the same endpoint -- never tight enough to catch a legitimate shared address on its own.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $categories = ['web', 'search', 'dashboard', 'notifications', 'export', 'pdf', 'reports', 'bulk', 'upload'];

        foreach ($categories as $name) {
            RateLimiter::for($name, fn (Request $request) => $this->limitsFor($name, $request));
        }

        // Pre-authentication, IP-only (no signed-in user exists yet): requesting a password-reset email/link.
        RateLimiter::for('password_reset', fn (Request $request) => [
            Limit::perMinute(config('rate_limits.password_reset.per_minute'))->by('ip:'.$request->ip()),
            Limit::perHour(config('rate_limits.password_reset.per_hour'))->by('ip:'.$request->ip()),
        ]);

        // Pre-authentication, IP-only: the public "Contact Us" form.
        RateLimiter::for('contact', fn (Request $request) => [
            Limit::perMinute(config('rate_limits.contact.per_minute'))->by('ip:'.$request->ip()),
            Limit::perHour(config('rate_limits.contact.per_hour'))->by('ip:'.$request->ip()),
        ]);

        // Account-administration actions (create/deactivate/reactivate/reset staff & parent accounts). User-keyed
        // only -- these already require manage-staff/manage-parents, so "user" here always means an admin, and a
        // whole admin office legitimately working through a stack of new accounts shares nothing to throttle.
        RateLimiter::for('admin', fn (Request $request) => [
            Limit::perMinute(config('rate_limits.admin.per_minute'))->by($this->identity($request)),
            Limit::perHour(config('rate_limits.admin.per_hour'))->by($this->identity($request)),
        ]);
    }

    /** @return list<\Illuminate\Cache\RateLimiting\Limit> */
    private function limitsFor(string $name, Request $request): array
    {
        $identity = $this->identity($request);

        $limits = [
            Limit::perMinute(config("rate_limits.{$name}.per_minute"))->by($identity),
            Limit::perHour(config("rate_limits.{$name}.per_hour"))->by($identity),
        ];

        if ($ipCeiling = config("rate_limits.{$name}.ip_per_minute")) {
            // The looser backstop: keyed by IP + category (never by the user), so it only ever engages when
            // the SAME address is responsible for an unusually large share of this one expensive category --
            // not simply because several different staff on one network each did their own normal amount of work.
            $limits[] = Limit::perMinute($ipCeiling)->by("ip:{$name}:{$request->ip()}");
        }

        return $limits;
    }

    /** The signed-in user's own id when there is one; otherwise the IP (e.g. the public id-verify/invitation-style routes, if ever added to a named category above). */
    private function identity(Request $request): string
    {
        return $request->user() ? 'user:'.$request->user()->id : 'ip:'.$request->ip();
    }
}
