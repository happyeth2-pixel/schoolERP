<?php

/*
| Application-level abuse protection for every part of the app EXCEPT signing in, which already has its own
| deeper, multi-layer system (see config/login_security.php and App\Security\LoginThrottle -- account/device/
| IP/burst layers, progressive cool-downs, never a permanent block). This file is that same idea -- centralized,
| documented, env-overridable numbers instead of magic numbers scattered through controllers -- applied to
| everything else: ordinary browsing, search, dashboards, exports, PDFs, reports, uploads, bulk actions,
| notifications and account-administration actions. See RateLimitServiceProvider for how these become named
| Laravel rate limiters, and RATE_LIMITING_AND_ABUSE_PROTECTION.md for the full policy table and the reasoning
| behind each number.
|
| Shape of each entry:
|   per_minute / per_hour  the two windows checked together (both must pass)
|   ip_per_minute          an extra, looser per-IP ceiling layered on top for the costlier categories -- catches
|                          one attacker spreading load across several accounts (or sessions) from one address,
|                          without affecting a school where many different staff share one public IP (each of
|                          THEIR per-user limits is independent; the IP ceiling is set well above what a whole
|                          staff room doing normal work would ever reach together). Omitted entirely for the
|                          cheap, high-frequency categories (web/search/dashboard/notifications), where adding
|                          one would risk exactly the "shared school network gets blocked" failure this whole
|                          design exists to avoid.
|
| Storage: whatever CACHE_STORE already is (database by default in this app -- see config/cache.php), the same
| shared, multi-instance-safe store Laravel's RateLimiter always uses. Never per-process memory, so these stay
| correct if the app is ever scaled to more than one instance.
*/

return [

    // Ordinary authenticated page views and everyday form submissions (attendance, marks, payments, every plain
    // CRUD action not called out below): generous enough that no normal person doing their job -- clicking
    // through pages, saving a form, submitting a batch of 40 attendance marks as ONE request -- ever notices it;
    // tight enough to stop a scripted loop hammering the app. Per signed-in user, not per IP, so a staff room
    // sharing one address never throttles each other.
    'web' => [
        'per_minute' => (int) env('RATE_WEB_PER_MINUTE', 300),
        'per_hour' => (int) env('RATE_WEB_PER_HOUR', 4000),
    ],

    // Quick Find (students), Find Parent, the library borrower search, and the small "reveal a few phone
    // numbers for the results on screen" endpoint. A receptionist typing fast, backspacing and retyping, still
    // sends at most a few requests a second (the page debounces every search box by 300ms already) -- this
    // leaves wide headroom above that while catching a scraper that walks every possible search term.
    'search' => [
        'per_minute' => (int) env('RATE_SEARCH_PER_MINUTE', 120),
        'per_hour' => (int) env('RATE_SEARCH_PER_HOUR', 2000),
    ],

    // The dashboard itself (loaded once per visit/navigation, not polled).
    'dashboard' => [
        'per_minute' => (int) env('RATE_DASHBOARD_PER_MINUTE', 60),
        'per_hour' => (int) env('RATE_DASHBOARD_PER_HOUR', 800),
    ],

    // The in-app notification bell and its mark-read actions.
    'notifications' => [
        'per_minute' => (int) env('RATE_NOTIFICATIONS_PER_MINUTE', 120),
        'per_hour' => (int) env('RATE_NOTIFICATIONS_PER_HOUR', 2000),
    ],

    // Excel exports (students/staff/invoices/attendance-roster/audit-log): each one builds and serializes the
    // WHOLE filtered dataset in-process (see PROJECT_SPEC_PART2.md's pagination work -- exports deliberately
    // never paginate, screens do). Genuinely rare and deliberate for a real person; strict on purpose, with an
    // IP backstop since this is the single most expensive synchronous operation in the app.
    'export' => [
        'per_minute' => (int) env('RATE_EXPORT_PER_MINUTE', 6),
        'per_hour' => (int) env('RATE_EXPORT_PER_HOUR', 40),
        'ip_per_minute' => (int) env('RATE_EXPORT_IP_PER_MINUTE', 20),
    ],

    // Report-card and receipt PDFs (DomPDF rendering -- CPU-bound, one request per document).
    'pdf' => [
        'per_minute' => (int) env('RATE_PDF_PER_MINUTE', 15),
        'per_hour' => (int) env('RATE_PDF_PER_HOUR', 150),
        'ip_per_minute' => (int) env('RATE_PDF_IP_PER_MINUTE', 40),
    ],

    // The eight accounting/financial reports (live DB aggregation over a date range, not paginated -- that's
    // the nature of a report). Generous enough that an administrator can click through every tab in one sitting.
    'reports' => [
        'per_minute' => (int) env('RATE_REPORTS_PER_MINUTE', 30),
        'per_hour' => (int) env('RATE_REPORTS_PER_HOUR', 400),
        'ip_per_minute' => (int) env('RATE_REPORTS_IP_PER_MINUTE', 80),
    ],

    // Deliberate, infrequent, high-fan-out actions: generating a grade's invoices for the year, promoting a
    // whole section, finalizing a payroll run. Each one writes many rows in a loop inside one request; a person
    // does this at most a handful of times a day, never back-to-back in a tight loop.
    'bulk' => [
        'per_minute' => (int) env('RATE_BULK_PER_MINUTE', 10),
        'per_hour' => (int) env('RATE_BULK_PER_HOUR', 60),
        'ip_per_minute' => (int) env('RATE_BULK_IP_PER_MINUTE', 30),
    ],

    // Profile/student photo uploads: each one re-encodes the image server-side (see App\Support\SafeUpload),
    // real CPU work, not just a DB write.
    'upload' => [
        'per_minute' => (int) env('RATE_UPLOAD_PER_MINUTE', 20),
        'per_hour' => (int) env('RATE_UPLOAD_PER_HOUR', 200),
        'ip_per_minute' => (int) env('RATE_UPLOAD_IP_PER_MINUTE', 60),
    ],

    // Staff/parent account lifecycle actions (create, deactivate, reactivate, reset password, unlock sign-in) --
    // sensitive (they change who can sign in), but a registrar legitimately processes a whole stack of new
    // accounts at the start of a term, so this stays well above that.
    'admin' => [
        'per_minute' => (int) env('RATE_ADMIN_PER_MINUTE', 40),
        'per_hour' => (int) env('RATE_ADMIN_PER_HOUR', 400),
    ],

    // Requesting a password-reset email/link (PasswordResetLinkController, NewPasswordController) -- always
    // pre-authentication, so keyed by IP alone. Laravel's own password broker already throttles re-sending to
    // the SAME account to once per 60 seconds; this covers the gap that left entirely unprotected: many
    // DIFFERENT accounts/addresses tried from one source, which is both a mail/notification-flooding vector and
    // an account-enumeration tool (the stock Breeze response differs for an address that exists vs. one that
    // doesn't). Deliberately close to LoginThrottle's own IP-pressure numbers (config/login_security.php) --
    // same kind of actor, same kind of network.
    'password_reset' => [
        'per_minute' => (int) env('RATE_PASSWORD_RESET_PER_MINUTE', 5),
        'per_hour' => (int) env('RATE_PASSWORD_RESET_PER_HOUR', 20),
    ],

    // Pre-authentication, IP-only, same shape as password_reset: the public "Contact Us" form.
    'contact' => [
        'per_minute' => (int) env('RATE_CONTACT_PER_MINUTE', 3),
        'per_hour' => (int) env('RATE_CONTACT_PER_HOUR', 10),
    ],

];
