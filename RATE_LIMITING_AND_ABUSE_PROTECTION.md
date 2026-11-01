# Rate Limiting & Abuse Protection

A full audit of the app's traffic handling, followed by a centralized, multi-layer rate-limiting system covering
every category of endpoint, not just sign-in (which already had its own, separate, deeper system before this
work — see "What already existed," below). Written the same day as the audit, 2026-10-17.

---

## 1. What already existed (audited, not rebuilt)

- **Sign-in itself** (`App\Security\LoginThrottle`, `config/login_security.php`): a four-layer system —
  per-account progressive cool-downs then a temporary lock, per-device (cookie) independence so one account
  being attacked never locks its real owner out of their own browser, per-IP "pressure" detection that slows
  down *unrecognized* browsers from a busy address without ever blocking a known one, and a hard per-IP burst
  cap. Documented in `AUTHENTICATION.md`. Audited as part of this work, found solid, **not modified**.
- **Two named Laravel rate limiters** (`AppServiceProvider::boot()`): `id-verify` (the public QR-code
  verification page, 30/min + 300/hour by IP) and `invitation` (the one-time "set your password" links,
  10/min + 60/hour by IP). Both already wired to their routes. **Not modified.**
- **A manual "unknown code" counter** inside `IdCardController::verify()` (8 misses/60s by IP) layered on top of
  `id-verify`, specifically so a security guard scanning a stack of genuinely expired cards is never throttled —
  only *unrecognized* tokens count as a miss. **Not modified.**

Everything below is new.

---

## 2. Audit findings

### 2a. Trusted proxy configuration — audited, confirmed already correct

The app runs on Render, which (like Heroku and similar platforms) terminates TLS at its own edge and forwards
every request to this container over plain HTTP with `X-Forwarded-For` set. `bootstrap/app.php` trusts proxies
with `at: '*'`. Read in isolation this looks alarming — "trust every proxy" sounds exactly like the setup that
lets an attacker spoof their IP by sending their own `X-Forwarded-For` header. **It is not.** Laravel's
`TrustProxies` middleware special-cases the literal string `'*'`: instead of trusting an unbounded chain, it
trusts **only the immediate TCP peer** (`REMOTE_ADDR`) and resolves the client IP from there — i.e. exactly the
"trust one hop" pattern that's correct for a single-hop PaaS edge, where the container is never directly
reachable from the public internet and `REMOTE_ADDR` is always Render's own edge, never an attacker's.

This was verified empirically, not assumed: a request built with `REMOTE_ADDR` set to a fake "edge" IP and an
`X-Forwarded-For` header forged as `6.6.6.6, 203.0.113.9` (attacker-prepended fake IP, then the edge's real
append) was run through the app's actual middleware stack. `request()->ip()` correctly resolved to `203.0.113.9`
(the real client) — **never** the attacker's forged `6.6.6.6`. **No change made.** This matters directly for
every limiter below: every one of them keys on `request()->ip()` or `request()->user()->id`, and this is the
property that makes the IP half of that trustworthy.

### 2b. A real, previously-unprotected gap: password reset

`POST /forgot-password` (request a reset email/link) and `POST /reset-password` (submit a new password) had
**zero rate limiting of any kind** — not even Laravel's own default `throttle:6,1`. Unlike sign-in, this
endpoint can be aimed at a *different* email address on every single request, so nothing stopped either a
mail/notification-flooding run or, since the stock response differs for an address that exists vs. one that
doesn't, using it to enumerate which accounts exist. **Fixed** — see `password_reset` below.

### 2c. The production web server itself

`Dockerfile`'s `CMD` runs `php artisan serve` — PHP's own **built-in development server** — as the actual
production process. This has no connection queue, no worker pool, no request-size/timeout hardening of its own;
by default it serves **one request at a time**, full stop. This session tested enabling `PHP_CLI_SERVER_WORKERS`
(a PHP 8 feature meant to pre-fork a small worker pool) as a quick mitigation, and found it **unreliable on this
platform during testing: POST requests hung indefinitely with it enabled**, even at a small worker count. Rather
than ship an unverified change to the production entrypoint, **this was deliberately left out** — the Dockerfile
still runs the plain single-request server, with a comment explaining why and pointing at the real fix. See
§5 ("Server-level recommendations") for what that real fix is. This is, by a wide margin, the most important
infrastructure item in this whole report — no amount of request-level rate limiting changes what the underlying
server can do when two people click "Export" one second apart.

### 2d. No queue worker exists

`QUEUE_CONNECTION=database` is configured, but nothing in the codebase currently implements `ShouldQueue` or
dispatches a job, and no worker process (`php artisan queue:work`) runs anywhere in the deployment (no
`render.yaml`, `Procfile`, or supervisor config defines one). This matters for the "should this be queued
instead" question the brief asked about — see §6.

---

## 3. The new system

### 3a. Shape

- **`config/rate_limits.php`** — every threshold, documented inline, every one overridable via `.env` without
  touching code. Nothing hard-coded in a controller.
- **`App\Providers\RateLimitServiceProvider`** — registers one named Laravel rate limiter per category, reading
  from that config. One small, shared identity rule, not copy-pasted per limiter.
- **`App\Support\RateLimitLog`** — the one place a violation is written to the existing `SecurityEvent` log,
  debounced (see §3d).
- **`bootstrap/app.php`** — the existing 429→friendly-Inertia-page handler now also (a) calls `RateLimitLog`,
  and (b) carries `Retry-After`/`X-RateLimit-*` headers onto that friendly page instead of silently dropping
  them.
- Applied to routes via `->middleware('throttle:<name>')` in `routes/web.php` / `routes/auth.php` — no new
  middleware classes, this is Laravel's own `ThrottleRequests` middleware, just pointed at named, config-driven
  limiters instead of inline numbers.

### 3b. Identification key — the "shared school network" requirement

**Every authenticated category keys on the signed-in user's own id (`user:{id}`), never the IP alone.** This is
the one decision that makes the whole system safe for a school where a receptionist, two teachers and an
accountant all share one public address: each of their own actions is throttled against *their own* budget,
completely independent of how many colleagues are on the same network doing their own (equally unthrottled-by-
each-other) work. Verified directly — see §4, "two different users sharing one IP."

The costlier categories (export, pdf, reports, bulk, upload) layer a **second, much looser per-IP ceiling** on
top — keyed by `ip:{category}:{ip}`, so it only ever engages when one address is responsible for an unusually
large share of *that one expensive category*, not simply because several different staff each did their own
normal amount of work. This catches the case a pure per-user limit can't: one attacker spreading load across
several accounts (or an anonymous angle on the same server resource) from one address.

Pre-authentication categories (`password_reset`) key on IP alone — there is no user yet.

### 3c. Storage

Laravel's `RateLimiter` is backed by whatever the app's default cache store is — nothing in this feature chooses
its own. This app's default is `database` (`config/cache.php`, `CACHE_STORE` env var) — a store every instance
of the app shares. **Never** `array` (one process's own memory, gone the instant it restarts) or `file` (one
container's own disk) in production, either of which would make limits inconsistent the moment the app ever runs
on more than one instance. Asserted directly by a test reading the config source (not the live test-time value,
which is deliberately `array` for the test suite's own speed — see `phpunit.xml`).

### 3d. Logging — without becoming the attack itself

The brief specifically warned that an attacker could turn rate-limit logging into its own exhaustion attack (one
row written per retry, forever). `RateLimitLog::record()` debounces: **at most one `SecurityEvent` row per
(route, identity) pair every 5 minutes**, no matter how fast the offender keeps retrying — everything in between
is still correctly blocked with a 429, just not individually logged. The debounce gate itself is a single cache
`add()` call (atomic, cheap), never a database read-then-write. The log entry carries only the route name, HTTP
method, and (for an authenticated violation) the user id — **never** the request body, so nothing anyone typed
into a throttled form (a password, a search term) ever reaches it. Visible to staff holding `view-audit-log` on
the existing Security Log page (`/audit-log/security`) as `rate_limited`.

### 3e. The 429 response itself

Laravel's own `ThrottleRequests` middleware already sets `Retry-After`, `X-RateLimit-Limit` and
`X-RateLimit-Remaining` correctly — that was never missing. What *was* missing: the app's own 429→friendly-page
conversion (added in an earlier session so a throttled request shows the same plain-language "Too many attempts
— please wait a short while" page as everything else, instead of Laravel's bare default view) was silently
**dropping those headers** when it built the replacement response. Fixed: the three headers are now copied onto
the friendly page. A real script that respects `Retry-After` still gets a correct number; a person just sees a
readable sentence either way.

---

## 4. Every policy, what it protects, and why

| Category | Routes | Key | Limit | Why this number |
|---|---|---|---|---|
| **web** (baseline) | Every other authenticated page/action — applied at the group level in `routes/web.php` | user | 300/min, 4000/hour | Generous enough that no normal clicking-around, or a teacher submitting one 40-row attendance batch as one request, ever notices it; still a real cap on a scripted loop. |
| **search** | `/students` (Quick Find), `/students/contacts`, `/parents`, `/parents/search`, `/library/borrowers/search` | user | 120/min, 2000/hour | The search box already debounces 300ms client-side (≈3.3 req/s max from one very fast typist); this leaves wide headroom above real typing while capping a scraper walking every possible term. |
| **dashboard** | `/dashboard` | user | 60/min, 800/hour | Loaded once per navigation, not polled. |
| **notifications** | `/notifications`, mark-read, mark-all-read | user | 120/min, 2000/hour | Generous, cheap action. |
| **export** | `staff.export`, `students.export`, `invoices.export`, `attendance.export`, `audit-log.export` | user **+ IP backstop** | 6/min, 40/hour (user) · 20/min (IP) | Each builds and serializes the *whole* filtered dataset in-process — the single most expensive synchronous operation in the app (see `PROJECT_SPEC_PART2.md`'s pagination work: exports deliberately never paginate). Rare and deliberate for a real person. |
| **pdf** | `report-card.pdf`, `payments.receipt` | user **+ IP backstop** | 15/min, 150/hour (user) · 40/min (IP) | DomPDF rendering is CPU-bound. |
| **reports** | The 8 accounting/financial reports under `/accounting/reports/*` | user **+ IP backstop** | 30/min, 400/hour (user) · 80/min (IP) | Live DB aggregation, not paginated (a report's whole point). Generous enough to click through all 8 tabs in one sitting. |
| **bulk** | `invoices.generate(+preview)`, `promotions.store`, `payroll-runs.store/finalize`, `stocktakes.complete` | user **+ IP backstop** | 10/min, 60/hour (user) · 30/min (IP) | Deliberate, infrequent, high-fan-out actions (generate a grade's invoices, finalize a payroll run) — each writes many rows in a loop inside one request; done a handful of times a day, never back-to-back. |
| **upload** | `profile.photo`, `students.photo` | user **+ IP backstop** | 20/min, 200/hour (user) · 60/min (IP) | Each re-encodes the image server-side (`App\Support\SafeUpload`) — real CPU work. |
| **admin** | Staff/parent account lifecycle: create, update, deactivate, reactivate, reset-password, unlock-sign-in | user | 40/min, 400/hour | Sensitive (changes who can sign in), but a registrar legitimately processes a stack of new accounts at term start. |
| **password_reset** *(new)* | `POST /forgot-password`, `POST /reset-password` | **IP** (always pre-auth) | 5/min, 20/hour | Closes the gap in §2b. Laravel's own password broker already throttles *re-sending to the same account* to once/60s; this covers the part that was wide open — many *different* addresses tried from one source (mail-flood + enumeration shape). |
| **id-verify**, **invitation** | (pre-existing, unchanged) | IP | see §1 | Unchanged. |
| **login** | (pre-existing, unchanged — `App\Security\LoginThrottle`) | account+device+IP+burst | see `config/login_security.php` | Unchanged; already the most sophisticated layer in the app. |

Routes not individually named above (most GET/POST CRUD across every module — academic structure, exams, marks,
library, inventory, procurement, accounting entries, HR, timetable, etc.) fall under the **web** baseline, which
is itself a real, enforced limit — just not a bespoke one. This was a deliberate scope decision given ~380 routes
in the app: the handful of genuinely expensive/sensitive categories above got bespoke policies; the long tail
gets a generous-but-real shared one rather than 300+ individually hand-tuned entries.

---

## 5. Server-level & infrastructure recommendations (not implemented — outside what this codebase controls)

1. **Replace `php artisan serve` with a real production web server.** This is the single most important item in
   this report. `php-fpm` behind `nginx` (or Laravel Octane / FrankenPHP) would give: a proper connection queue
   and worker pool, request body/upload size limits enforced *before* PHP even starts, slow-client (slowloris)
   protection, and genuine concurrency. Every rate limit in this document assumes requests are actually being
   served — none of it compensates for a server that can only handle one request at a time.
2. **Put a CDN/WAF in front of the app** (Cloudflare or equivalent) if the school ERP is ever exposed to the
   general internet at meaningful scale. This document's rate limiting is **application-level abuse protection**
   — it stops one account or one address from consuming more than its fair share of *this app's* resources. It
   is explicitly **not** DDoS protection: a distributed attack across thousands of IPs, each staying under every
   per-IP/per-user threshold here, will still reach and load this application. Only an edge layer that can
   absorb and filter traffic *before* it reaches the origin (Cloudflare, AWS Shield, Render's own DDoS
   protection if offered) addresses that. Nothing in Laravel, including everything built this session, claims
   to.
3. **Upload/body size limits**: currently governed only by PHP's own defaults (`upload_max_filesize`,
   `post_max_size` — not overridden anywhere in this image) plus the app's own 2MB validation rule on photo
   uploads (`SafeUploadRule`). A dedicated `php.ini` (or `-d` flags in the Dockerfile `CMD`) setting
   `upload_max_filesize`/`post_max_size` explicitly, and `max_execution_time`/`max_input_time` tuned for the
   slowest legitimate request (a large export), is recommended once the server itself is replaced per #1 —
   tuning these on the built-in dev server has limited value.
4. **TLS/HTTP already correctly handled**: `EnforceHttps` + HSTS + the trusted-proxy configuration audited in
   §2a are already correct for the current Render deployment; no change needed there.

---

## 6. Queue — evaluated, deliberately not changed

The brief asked to evaluate moving expensive operations (exports, PDFs) to a queue instead of just rate-limiting
them. **Evaluated, not implemented, for one concrete reason: no queue worker process exists anywhere in this
deployment** (§2d). `QUEUE_CONNECTION=database` is configured but inert — nothing consumes it. Converting
exports/PDFs to dispatch a queued job without first standing up a real worker (a second Render service running
`php artisan queue:work`, with its own restart/monitoring story) would **silently break the feature** — the
"download" button would appear to do nothing, forever, with no worker ever picking up the job. That would be
strictly worse than the current synchronous behavior, which at least works. Given the explicit instruction not
to break existing functionality, this was the responsible call: strengthen rate limits on these endpoints now
(done, §4), leave them synchronous, and treat "add a real worker + convert exports to queued jobs" as its own
properly-scoped follow-up for whenever a school's export volume genuinely outgrows synchronous generation —
which, per the exports' current real-world size (a few thousand rows, one-shot `PhpSpreadsheet`/DomPDF build,
seconds not minutes), is not yet the case.

---

## 7. Database protections — audited in context, mostly already addressed

Pagination, N+1 eager-loading, and export-vs-screen separation for the costliest list pages were already audited
and fixed in a separate, prior pass this project — see `PROJECT_SPEC_PART2.md` and the git history around
`StudentController`/`StaffController`/`ParentController`/`InvoiceController`. That work is what makes the
**export** and **search** rate-limit numbers above meaningful (there is now a real, bounded cost per request to
rate-limit against, not an unbounded "load everything" query). No new database changes were made in this pass;
this report's job was the traffic layer sitting in front of that already-hardened data layer.

---

## 8. Tests

`tests/Feature/RateLimitingTest.php` — 27 tests, all passing:

- Ordinary use well under a limit is never throttled.
- Exceeding a configured limit returns 429.
- The 429 response carries `Retry-After` and `X-RateLimit-*` headers.
- The 429 is the app's own friendly Inertia error page (headers survive being wrapped).
- **Two different users sharing one IP each get their own, independent budget** (the core "shared school
  network" requirement).
- One account cannot dodge its own limit by appearing from a different IP each request (per-user key holds
  regardless of claimed address).
- Anonymous (pre-auth) requests are correctly keyed by IP.
- Password-reset abuse: the previously-unthrottled gap is now closed, for both the request-link and
  confirm-new-password forms.
- Export abuse is throttled tighter than ordinary browsing, while an ordinary page for the same user in the same
  minute is unaffected (separate limiters, not cross-contaminating).
- The IP backstop catches several *different* accounts hammering exports from one address, even though each
  account is individually still under its own generous per-user budget.
- Upload abuse, bulk-generation abuse, and financial-report abuse (shared budget across all 8 report pages) are
  each throttled.
- **Route coverage**: a data-provider test asserts the expected `throttle:<name>` middleware is still actually
  attached to every named heavy route — catches a future refactor silently dropping protection.
- **Bypass attempts**: extra/different query parameters don't create a fresh budget; a `HEAD` request counts
  against the same budget as `GET` on the same route.
- Logging is debounced (five rapid retries produce exactly one `SecurityEvent` row, not five) and never contains
  the request body (a throttled password-reset attempt's email address never appears in the logged event).
- The app's default cache store is asserted (from the config source) to be a shared one, not `array`/`file`.

Full backend suite: **1149 passed**, 0 regressions, run after every change in this pass.

---

## 9. Load / stress testing — what was genuinely done, and what was not

**Honestly scoped, per the standing instruction not to claim something was tested if it wasn't:**

- PHPUnit's test client makes in-process, single-threaded requests — it can prove the *logic* is correct
  (identity keying, header behavior, debounced logging, route coverage) but **cannot** exercise real concurrency
  or race conditions. The 27 tests above are exactly that: logic verification, not a load test.
- A genuine multi-connection concurrency test was attempted this session, using real HTTP connections
  (Python + `ThreadPoolExecutor`) against a locally-running `php artisan serve` instance with
  `PHP_CLI_SERVER_WORKERS` enabled to get real parallelism. **This is what surfaced the POST-hang finding in
  §2c** — the test infrastructure itself exposed a genuine, serious production risk before any code shipped
  with it. Once that risk was found, the concurrency test could not be completed safely (the same unreliable
  worker mode would have been needed to generate real parallel load), so it was stopped rather than pushed
  through on an already-identified-as-unstable configuration.
- **No test at the scale of "hundreds/thousands of concurrent users" or a simulated distributed attack was
  performed.** This sandboxed development environment is not a meaningful proxy for that regardless of tooling
  — a truthful answer requires a real load-testing tool (k6, Locust, Apache Bench, or similar) run against a
  staging deployment of the **actual production image** (Linux, behind the real web server once §5#1 is
  addressed), not a local Windows dev box. This is the single largest piece of unfinished verification work
  from this pass, and it is explicitly flagged rather than glossed over.

---

## 10. Remaining risks / infrastructure work before exposing this to large numbers of users

In priority order:

1. **Replace `php artisan serve` with a real production web server** (§5#1). Everything else in this document
   is secondary to this.
2. **Run a real load test** (§9) against a staging deployment of the production image, once #1 is done —
   measure actual response time, memory, DB connection usage and error rate under realistic concurrent load, and
   re-tune the thresholds in `config/rate_limits.php` against real numbers instead of estimated ones.
3. **Put an edge/WAF layer in front of the app** if it will ever be reachable by the general internet at
   meaningful scale (§5#2) — this codebase's rate limiting is application-level abuse protection, not DDoS
   protection, and was never claimed to be.
4. The long tail of routes under the generic **web** baseline could, over time, get their own bespoke categories
   if real traffic data shows a specific one deserves tighter or looser treatment than 300/min — not done
   proactively here to avoid 300+ speculative, untested threshold decisions.
5. If exports/reports genuinely outgrow synchronous generation for a real school's data volume, revisit §6 (a
   real queue worker is the correct next step then, not before).
