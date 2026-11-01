<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A regression safety net for this app's own architecture: every permission check lives inside the
 * controller, never in route middleware or a Policy (see PROJECT_SPEC_PART2.md Sections 3/31) --
 * "a future endpoint can accidentally forget a check" was raised as the real risk of that pattern. This
 * does not change the architecture (that would be a full rewrite); it scans every public action on every
 * controller and fails if the action's source contains none of the recognised authorization markers AND
 * it is not in the ALLOWED_WITHOUT_CHECK whitelist below.
 *
 * The whitelist exists for actions that genuinely need no check beyond "a real session exists" (already
 * enforced by the global `auth` middleware) -- a self-service "my own X" page, a public pre-login page, or
 * one that defers entirely to a FormRequest's own authorize()/rules(). Every entry needs a one-line reason;
 * adding one is a deliberate, reviewed decision, never a way to silence a genuine finding. If this test
 * fails on a real controller action, that is the thing to fix -- add the missing check, don't whitelist it.
 */
class ControllerAuthorizationAuditTest extends TestCase
{
    /** @var array<string, string> "App\Http\Controllers\Foo@bar" => why it needs no in-method check */
    private const ALLOWED_WITHOUT_CHECK = [
        // ---- Pre-authentication / genuinely public (no session exists yet) ----
        'App\Http\Controllers\Auth\AuthenticatedSessionController@create' => 'the login page itself',
        'App\Http\Controllers\Auth\AuthenticatedSessionController@store' => 'LoginRequest::authenticate() does the real check',
        'App\Http\Controllers\Auth\AuthenticatedSessionController@destroy' => 'signing out needs no permission',
        'App\Http\Controllers\Auth\PasswordResetLinkController@create' => 'the forgot-password page itself',
        'App\Http\Controllers\Auth\PasswordResetLinkController@store' => 'rate-limited by the password_reset limiter; reveals nothing about which accounts exist',
        'App\Http\Controllers\Auth\NewPasswordController@create' => 'the reset-password page itself',
        'App\Http\Controllers\Auth\NewPasswordController@store' => 'the reset token itself is the credential (Password::reset())',
        'App\Http\Controllers\Auth\InvitationController@show' => 'unknown/used/revoked/expired links all render the same generic page, by design',
        'App\Http\Controllers\Auth\InvitationController@accept' => 'the invitation token itself is the credential',
        'App\Http\Controllers\Auth\EmailVerificationNotificationController@store' => 'only ever acts on the already-authenticated requester\'s own account',
        'App\Http\Controllers\Auth\VerifyEmailController@__invoke' => 'the signed verification URL itself is the credential',
        'App\Http\Controllers\Auth\ConfirmablePasswordController@show' => 'the re-authentication prompt itself',
        'App\Http\Controllers\Auth\ConfirmablePasswordController@store' => 'checks the requester\'s own current_password, nothing else',
        'App\Http\Controllers\Auth\EmailVerificationPromptController@__invoke' => 'tells the already-authenticated requester whether their own email is verified',
        'App\Http\Controllers\Auth\PasswordController@update' => 'only ever acts on the already-authenticated requester\'s own password',
        'App\Http\Controllers\Auth\RequiredPasswordChangeController@show' => 'only ever reads the already-authenticated requester\'s own must_change_password flag',
        'App\Http\Controllers\Auth\RequiredPasswordChangeController@update' => 'only ever acts on the already-authenticated requester\'s own account',

        // ---- Public, unauthenticated-by-design product features ----
        'App\Http\Controllers\IdCardController@verify' => 'the public QR-code verification page; deliberately discloses only a Valid/Expired/Suspended/Invalid state',
        'App\Http\Controllers\PublicSiteController@home' => 'the logged-out marketing home page; anyone may view it',
        'App\Http\Controllers\PublicSiteController@about' => 'the logged-out marketing about page; anyone may view it',
        'App\Http\Controllers\PublicSiteController@contact' => 'the logged-out marketing contact page; anyone may view it',
        'App\Http\Controllers\PublicSiteController@submitContact' => 'anyone may submit the public contact form; rate-limited by the contact limiter',

        // ---- Self-service "my own X" pages: every signed-in account may see only its own data,
        // scoped by the requester's own id, never a route parameter -- the `auth` middleware already
        // covers "must have a real session"; no further permission exists to check. ----
        'App\Http\Controllers\ProfileController@edit' => 'the requester\'s own profile',
        'App\Http\Controllers\ProfileController@update' => 'the requester\'s own profile',
        'App\Http\Controllers\ProfileController@destroy' => 'the requester\'s own account',
        'App\Http\Controllers\ProfileController@updatePhoto' => 'the requester\'s own profile photo',
        'App\Http\Controllers\ProfileController@updateLocale' => 'the requester\'s own display language',
        'App\Http\Controllers\NotificationController@index' => 'the requester\'s own notifications',
        'App\Http\Controllers\NotificationController@markRead' => 'the requester\'s own notifications',
        'App\Http\Controllers\NotificationController@markAllRead' => 'the requester\'s own notifications',
        'App\Http\Controllers\DashboardController@index' => 'renders a different variant per the requester\'s own role; every figure inside is independently permission-gated',
        'App\Http\Controllers\HelpController@index' => 'static, role-agnostic help content',
        'App\Http\Controllers\LeaveRequestController@mine' => 'the requester\'s own leave history',
        'App\Http\Controllers\PerformanceReviewController@mine' => 'the requester\'s own finalized reviews',
        'App\Http\Controllers\PayrollRunController@mine' => 'the requester\'s own payslips',
        'App\Http\Controllers\LibraryLoanController@mine' => 'the requester\'s own loan history',
        'App\Http\Controllers\TimetableController@mine' => 'the requester\'s own timetable',
        'App\Http\Controllers\OptionalServiceController@myPrograms' => 'only the programmes the requester is the named teacher of',
        'App\Http\Controllers\FamilyController@index' => 'the requester\'s own linked children, via isGuardianOf()',
        'App\Http\Controllers\FamilyController@attendance' => 'the requester\'s own linked children, via isGuardianOf()',
        'App\Http\Controllers\FamilyController@invoices' => 'the requester\'s own linked children, via isGuardianOf()',
        'App\Http\Controllers\FamilyController@timetable' => 'the requester\'s own linked children, via isGuardianOf()',
        'App\Http\Controllers\FamilyController@homework' => 'the requester\'s own linked children, via isGuardianOf()',
        'App\Http\Controllers\ReportCardController@family' => 'the requester\'s own linked children, via isGuardianOf()',
        'App\Http\Controllers\ParentMessageController@inbox' => 'scoped per-role to the requester\'s own students/sections inside the method',
    ];

    public function test_every_controller_action_checks_authorization_or_is_explicitly_whitelisted(): void
    {
        $failures = [];
        $resolving = [];

        foreach ($this->controllerClasses() as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue; // inherited (e.g. from Controller), not this controller's own action
                }
                if ($method->isConstructor() || $method->isStatic() || str_starts_with($method->getName(), '__')) {
                    continue;
                }

                $key = $class.'@'.$method->getName();
                if (array_key_exists($key, self::ALLOWED_WITHOUT_CHECK)) {
                    continue;
                }

                $resolving = [];
                if (! $this->methodChecksAuthorization($reflection, $method, $resolving)) {
                    $failures[] = $key;
                }
            }
        }

        $this->assertSame([], $failures, "These controller actions have no recognised authorization marker and are not in ControllerAuthorizationAuditTest::ALLOWED_WITHOUT_CHECK:\n".implode("\n", $failures));
    }

    /** @return list<class-string> */
    private function controllerClasses(): array
    {
        $dir = dirname(__DIR__, 2).'/app/Http/Controllers';
        $classes = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $class = 'App\\Http\\Controllers\\'.str_replace('/', '\\', substr($relative, 0, -4));

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @param array<string, bool> $resolving guards against infinite recursion between two helpers that call each other */
    private function methodChecksAuthorization(ReflectionClass $class, ReflectionMethod $method, array &$resolving): bool
    {
        $key = $class->getName().'@'.$method->getName();
        if (isset($resolving[$key])) {
            return false; // already being resolved further up the call chain -- treat as "no evidence yet"
        }
        $resolving[$key] = true;

        $source = $this->methodSource($method);
        if ($source === null) {
            return true; // couldn't read the source (shouldn't happen) -- don't produce a false failure
        }

        if (preg_match('/\b(abort|abort_unless|abort_if)\s*\(|->authorize\s*\(|Gate::|->can\s*\(|->cannot\s*\(|\b403\b/', $source)) {
            return true;
        }

        // One level (recursively) into any $this->helper(...) call this method makes, in case the real
        // check lives inside a shared private/protected helper (e.g. canView()/assertInScope()).
        if (preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $matches)) {
            foreach (array_unique($matches[1]) as $helperName) {
                if (! $class->hasMethod($helperName)) {
                    continue;
                }
                $helper = $class->getMethod($helperName);
                if ($helper->getName() === $method->getName()) {
                    continue;
                }
                if ($this->methodChecksAuthorization($helper->getDeclaringClass(), $helper, $resolving)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function methodSource(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if (! $file || ! $start || ! $end) {
            return null;
        }

        $lines = @file($file);
        if (! $lines) {
            return null;
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
