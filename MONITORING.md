# Production Monitoring & Error Reporting — School ERP

**Goal of this document:** know something is broken before the school calls you. This covers application error monitoring (backend PHP and frontend JavaScript), server/database health, backup success/failure, environment separation, what is deliberately kept out of third-party monitoring, and the incident procedure to follow once something is actually wrong.

**Last actually verified:** 2026-09-23 (see [What was actually verified](#what-was-actually-verified) below).

**Who is responsible:** same as `DISASTER_RECOVERY.md` — this is a one-person project, and the owner-developer is responsible for the monitoring infrastructure existing, for reacting to its alerts, and for periodically confirming it still works.

---

## What is monitored, and how

| Concern | Tool | Where it's configured |
|---|---|---|
| Backend (PHP/Laravel) exceptions | Sentry | `config/sentry.php`, `bootstrap/app.php` |
| Frontend (JavaScript/React) errors | Sentry | `resources/js/monitoring.js`, injected via `resources/views/app.blade.php` |
| Application availability + database connectivity | `/up` health check + an external uptime monitor | `App\Listeners\CheckApplicationHealth`; external monitor of your choice pointed at `/up` |
| HTTPS certificate expiry | The same external uptime monitor | Most uptime monitors (UptimeRobot, Better Uptime, etc.) include this for free on any HTTPS URL they watch |
| Server CPU / RAM / disk | Render's own built-in dashboard | Render dashboard → your service → "Metrics" tab — nothing to configure, it's there by default |
| Backup success/failure | A heartbeat ("dead man's switch") ping | `App\Support\Monitoring\Heartbeat`, called from `backup:run`/`backup:verify` (see `DISASTER_RECOVERY.md`) |

Nothing here requires a large dashboard/DevOps platform: it's one error-monitoring account (Sentry, free tier is enough at this scale), one external uptime-monitor account (free tier), and Render's own dashboard, which already exists.

### Setting it up (one-time)

1. **Sentry** — create a free account at sentry.io, and **two** projects inside it: one PHP/Laravel project (for the backend) and one JavaScript/React project (for the frontend) — Sentry treats these as different platforms and they need separate DSNs. Set:
   ```
   SENTRY_LARAVEL_DSN=<the PHP project's DSN>
   SENTRY_JS_DSN=<the JavaScript project's DSN>
   ```
   on the Render **web service's** environment (not the backup Cron Job — the backend DSN only matters where requests are actually handled). Nothing else to configure; `config/sentry.php` does the rest.
2. **Uptime monitor** — create a free account with any provider (UptimeRobot, Better Uptime, Freshping, Pingdom, ...) and add one HTTP(S) monitor pointed at `https://<your-render-url>/up`, checking every 5 minutes, alerting on anything other than HTTP 200. Turn on that same monitor's SSL/TLS certificate expiry check (most providers offer this as a checkbox on the same monitor, not a separate product) so you're warned weeks before a certificate would lapse — though Render manages your TLS certificate automatically for its own domains, so this mainly matters if you ever add a custom domain.
3. **Backup heartbeat** — see `DISASTER_RECOVERY.md`'s setup section; the short version is a free Healthchecks.io check (or equivalent) per backup command, with its ping URL set as `MONITORING_HEARTBEAT_BACKUP_RUN_URL` / `MONITORING_HEARTBEAT_BACKUP_VERIFY_URL` on the Cron Job's environment.
4. **Render's CPU/RAM/disk dashboard** needs no setup — just look at it periodically, or use your uptime monitor's own status-page/notification features if you want a push alert when Render's own metrics cross a threshold (Render itself does not currently offer alerting on these, only the dashboard view).

---

## Environments: local / staging / production

Every event Sentry receives is tagged with an `environment` (from `APP_ENV`, or `SENTRY_ENVIRONMENT` to override) so a test error on a developer's laptop can never be mistaken for a real incident:

- **local** — a developer's own machine. `config/sentry.php` hard-disables both the backend and frontend DSNs outright whenever `APP_ENV` is `local` or `testing`, **regardless of what's actually in `.env`** — a `.env` copied from production with a real DSN still in it cannot leak local noise into the real Sentry project. This is enforced in code, not by convention alone.
- **staging** — not provisioned today (this project currently has one Render web service and one database). The moment a staging Render service exists, set `APP_ENV=staging` on it and everything above works immediately with no code changes: its errors land in the same Sentry projects, clearly tagged `environment: staging`, filterable separately from `production` in Sentry's own UI.
- **production** — the live Render service testers actually use. `APP_ENV=production`.

The same separation applies to `release`: every event also carries a `release` tag, which is Render's own `RENDER_GIT_COMMIT` (set automatically on every deploy) unless overridden by `SENTRY_RELEASE`. This is what lets Sentry tell you "this error started appearing in release X" and group issues by the deploy that introduced them, and it's shared between the backend and frontend SDKs so a single deploy's errors on both sides can be correlated.

---

## What is NOT sent to Sentry (PII / payment / auth scrubbing)

This app handles children's personal information and payment details, so what leaves the server matters. Nothing here is opt-in or configurable per environment — the same scrubbing runs unconditionally, in every environment, so it can't be accidentally left off.

**Backend** (`App\Support\Monitoring\SentryScrubber`, wired as Sentry's `before_send` in `config/sentry.php`) strips, wherever it appears — request body, query string, breadcrumbs, extra context, tags:
- **Authentication material**: passwords, tokens, secrets, passphrases, API keys, the `Authorization`/`Cookie` headers, and cookies entirely.
- **Personal information**: names (first/father/grandfather/mother/guardian), phone numbers, email addresses, physical addresses, date of birth, emergency contacts.
- **Payment/financial detail**: bank account numbers/names, payment reference numbers, receipt numbers, uploaded proof file content.
- **Free-text reasons** (discount/withdrawal/rejection/reopen/override reasons) — treated as personal information by default, since people write things like "family financial hardship" or "student ill" into them.
- The **query string and full URL** are stripped down to origin+path (a raw query string can carry a search term or an id that round-trips to a name; see the drill findings below for why this needed its own explicit handling, not just key-based filtering).
- **Source-code context** around stack frames is disabled entirely (`context_lines: null`) — Sentry's default behavior of attaching the actual PHP source lines around each stack frame is not something field-name-based scrubbing can safely filter (it's just text, not structured data), and a real drill (below) proved it would otherwise leak literal sample data sitting in nearby source lines.
- **The signed-in user** is never sent as a name or email. `App\Http\Middleware\AttachMonitoringContext` instead attaches a **pseudonymous, non-reversible id** (`hash_hmac('sha256', $userId, APP_KEY)`, truncated) plus their role and school/branch id — enough to tell support "this keeps happening to one accountant in branch 4" without exposing who that is. `send_default_pii` stays `false`, which is what would otherwise auto-attach the requester's real IP address and cookies.

**Frontend** (`resources/js/monitoring.js`) mirrors the same policy — the same field-name list, applied to breadcrumb data and request context before an event leaves the browser — and additionally:
- **Session Replay is never enabled.** It would record on-screen content (names, phone numbers, financial figures) by its very nature; this is a deliberate decision, not an oversight.
- **Performance tracing is off** (`tracesSampleRate: 0`, both sides) — this is error monitoring, kept deliberately simple; raise it later if request-performance data becomes worth the added Sentry quota.
- DOM interaction breadcrumbs (clicks, input) keep the element selector Sentry records but drop any captured text content.

---

## The `/up` health check

`GET /up` is what an external uptime monitor should watch. Laravel's own default `/up` only proves "PHP is running" — `App\Listeners\CheckApplicationHealth` (registered on Laravel's `DiagnosingHealth` event in `AppServiceProvider::boot()`) makes it also prove the **database** and **cache** are actually reachable: a trivial `select 1` and a cache round-trip. If either fails, `/up` returns HTTP 500 (Laravel's own contract for a listener exception on this event) **and** that failure is reported to Sentry too, since it flows through `report()` like any other exception.

`/up` is excluded from Sentry's transaction/performance tracking (`ignore_transactions` in `config/sentry.php`) since an uptime monitor hitting it every few minutes would otherwise be pure noise — its *failures* are still reported as exceptions regardless.

---

## Incident procedure

A short, concrete runbook — not a large process, just enough structure that an incident doesn't turn into panic.

### 1. Detect
An incident becomes known through one of: a Sentry alert (email/notification for a new or spiking issue), an uptime-monitor alert (`/up` failing, or the site unreachable), a backup heartbeat going silent/failing, or — the one no tool catches — a school calling or emailing to say something is broken.

### 2. Investigate
- **Sentry-reported error**: open the issue. It has the exception, full stack trace, the route/URL (path only), environment, release, and the pseudonymous actor tag. Check "how many times / how many distinct users" to judge severity, and whether it started right after a specific release (Sentry groups by release automatically).
- **Uptime/health failure**: check Render's own service logs and metrics dashboard first (is the service even running? Out of memory? Database plan exhausted?). If `/up` specifically fails but the service is up, the database is the likely culprit — check Render's database dashboard/status.
- **A school's report with no matching Sentry issue**: reproduce it yourself (or ask for the exact steps/screenshot); if it reproduces, it should have alerted — check whether Sentry is actually configured on that environment (§ Environments above) before assuming the monitoring itself is broken.
- Check `/audit-log` if the report concerns a specific record ("why does this invoice look wrong") — it often answers the question directly without needing to touch code at all.

### 3. Fix
- Branch from `main`. Write the fix. Add or update a test that would have caught it (this codebase's convention throughout — see the test suites already in `tests/`).
- Run `php artisan test` (and `npm run build` if frontend code changed) before considering it done.

### 4. Deploy
- Merge/push to `main`, which triggers Render's normal deploy of the web service (this repo's existing deploy path — see `Dockerfile`). No special incident-deploy process; the same pipeline used for ordinary changes.

### 5. Verify
- Confirm `/up` is green again (manually, or wait for the uptime monitor's next check).
- Re-check the specific broken flow by hand (log in as the relevant role, do the exact thing that failed).
- Watch the Sentry issue: it should stop recurring on the new release. Sentry lets you mark an issue "Resolved in the next release," which will automatically re-open it if it recurs — use that instead of just archiving it.

### 6. Document
- Add an entry to the incident log below: date, what broke, user impact, root cause, the fix (commit/PR reference), and anything to prevent recurrence. Two sentences is enough for a minor issue; more for anything that affected real data or took real downtime.
- If the incident revealed a monitoring gap (an error that should have alerted but didn't, a scrubbing gap, a missing test), fix that too, and note it here.

### Incident log

| Date | What broke | Impact | Root cause | Fix | Notes |
|---|---|---|---|---|---|
| _(none yet)_ | | | | | |

---

## What was actually verified

Per the same standard as `DISASTER_RECOVERY.md`: a monitoring setup that has never actually fired is not proven. Since no real Sentry account or uptime-monitor account exists for this project yet, everything below was verified against real, running stand-ins built for this purpose — not just reasoned about.

**Backend Sentry pipeline, end-to-end, against a local mock Sentry envelope receiver** (a tiny HTTP server standing in for sentry.io's ingest endpoint): a real HTTP request was dispatched through the **full** Laravel HTTP kernel — every middleware included (`EnsureAccountIsActive`, `AttachMonitoringContext`, `HandleInertiaRequests`, the lot) — to a throwaway route that throws, with realistic sensitive data in the request (a password, a guardian's real-shaped email and phone number, a free-text "reason," a name), in both the query string and the request body. The captured, gzip-decompressed envelope that the SDK actually sent was inspected byte-for-byte. This is what that drill found, in order:

1. **First run: three real leaks, found and fixed.**
   - The query string and full URL are captured by Sentry's Laravel integration as **raw strings**, not key/value arrays — the scrubber's key-based filtering silently didn't apply to them at all. **Fixed**: they're now stripped unconditionally (`SentryScrubber::scrubRequest()`), matching what the frontend scrubber already did.
   - Sentry's stack-trace **source-context** feature (on by default) attaches the actual PHP source lines around each frame — plain text, not structured data, so no field-name-based rule could ever safely filter it. It captured literal string values straight out of the test script's own source code. **Fixed**: disabled entirely (`'context_lines' => null`), not partially mitigated.
   - A unit test (`test_the_query_string_and_url_are_stripped_not_just_filtered_by_key`) now guards the first fix permanently.
2. **Second run, after both fixes, against the same mock receiver**: the password, the email, the phone number, the free-text reason and the name **did not appear anywhere** in the decompressed envelope, searched for as raw substrings — not "filtered to a similar-looking value," genuinely absent. The event correctly carried `environment: "staging"` (the drill's `APP_ENV` override), a `release` tag, the real exception type and message, and the correct route (`/__monitoring_drill`) as its transaction name — confirming the parts that are *supposed* to survive (enough to actually debug with) still do.

**`/up`'s database check**: `App\Listeners\CheckApplicationHealth` was exercised directly against a real database connection pointed at a nonexistent path — confirmed it throws (and so would fail `/up` with a 500, per Laravel's own documented contract for a `DiagnosingHealth` listener exception) — and against the real, working local database, confirmed it passes silently. `tests/Feature/HealthCheckTest.php`.

**The backup heartbeat**: `App\Support\Monitoring\Heartbeat` was tested against a faked HTTP client confirming a success pings the plain URL, a failure pings the `/fail`-suffixed URL (Healthchecks.io's convention), the suffix is configurable for a different provider's convention, no URL configured means no request at all, and a network failure while pinging never propagates and fails the caller. `tests/Unit/HeartbeatTest.php`.

**Frontend wiring**: confirmed live in a real browser (not just built successfully) that `window.__MONITORING__` is correctly injected with `dsn: null` on `local` (so `initMonitoring()` is a no-op, exactly as designed), that the app renders and navigates with zero console/page errors with monitoring code present, and — after finding and fixing a real gap where the pseudonymous user tag stayed stale immediately after login (Inertia's SPA-style navigation after login never re-renders the Blade template the DSN/environment come from) — confirmed via the actual server response payload that `monitoringUserPseudonym` is correctly populated the moment a user is authenticated and stays correct across subsequent page visits.

**What this did NOT verify, and what to do about it:**
- No real Sentry account was used, so the *actual* sentry.io ingest endpoint's behavior (auth, rate limits, how issues group in its UI) is untested. **Action**: the first time real DSNs are configured, deliberately trigger one backend error and one frontend error and confirm both appear correctly in their respective Sentry projects, tagged with the right environment — a five-minute check, not a repeat of the full drill.
- No real uptime-monitor or heartbeat-service account was used. **Action**: once configured, force one failure of each (stop the app briefly for the uptime monitor; skip a day's backup or call `backup:run` with a wrong passphrase for the heartbeat) and confirm an actual alert arrives.
- Sentry's frontend SDK was verified for correct configuration and scrubbing logic (via the browser check above and by code review against the same policy as the backend, which WAS drilled against a real receiver) but not against a real ingest endpoint capturing a genuine thrown React error. **Action**: covered by the same real-DSN check above — throw a real error in a dev build pointed at the real `SENTRY_JS_DSN` once it exists, and confirm it arrives scrubbed.
