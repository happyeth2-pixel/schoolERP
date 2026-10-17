import * as Sentry from '@sentry/react';

/**
 * Mirrors App\Support\Monitoring\SentryScrubber's policy on the backend: personal information,
 * payment/financial detail and anything auth-shaped is stripped wherever it could show up in a
 * frontend error report (breadcrumb data, request context, extra context) before the event ever
 * leaves the browser. See that PHP class's docblock for the full rationale; kept in sync with it
 * deliberately -- if you add a field there, add it here too.
 */
const SENSITIVE_KEY_FRAGMENTS = [
    'password', 'passphrase', 'secret', 'token', 'authorization', 'api_key', 'apikey',
    'phone', 'email', 'address', 'emergency_contact', 'date_of_birth', 'dob',
    'father_name', 'mother_name', 'grandfather_name', 'first_name', 'guardian_name', 'guardian_email', 'guardian_phone',
    'account_number', 'account_name', 'reference_number', 'receipt_number', 'proof',
    'ssn', 'national_id', 'reason',
];

function looksSensitive(key) {
    const k = String(key).toLowerCase();

    return SENSITIVE_KEY_FRAGMENTS.some((fragment) => k.includes(fragment));
}

function scrub(value) {
    if (value === null || typeof value !== 'object') {
        return value;
    }
    if (Array.isArray(value)) {
        return value.map(scrub);
    }

    return Object.fromEntries(
        Object.entries(value).map(([key, v]) => [key, looksSensitive(key) ? '[Filtered]' : scrub(v)]),
    );
}

/** Keeps the path (useful: "this broke on the invoice page") but drops the query string (a search box's contents, an id that round-trips to a name, ...). */
function scrubUrl(url) {
    try {
        const u = new URL(url, window.location.origin);

        return u.origin + u.pathname;
    } catch {
        return url;
    }
}

/**
 * Initializes Sentry for the browser using config injected server-side (see the inline script in
 * resources/views/app.blade.php) rather than a Vite build-time env var, and does nothing at all
 * when no DSN is configured -- which is always true on local/testing (config/sentry.php hard-disables
 * it there) and true in production too until SENTRY_JS_DSN is actually set.
 *
 * Deliberately NOT enabled: Session Replay (it would record on-screen content -- student names,
 * phone numbers, financial figures -- by its very nature) and performance tracing (kept simple;
 * this is error monitoring, see MONITORING.md).
 */
export function initMonitoring() {
    const config = window.__MONITORING__;
    if (!config?.dsn) {
        return;
    }

    Sentry.init({
        dsn: config.dsn,
        environment: config.environment,
        release: config.release,
        tracesSampleRate: 0,
        integrations: [],
        ignoreErrors: [
            // Browser/extension noise that is never an actionable bug in this app's own code.
            'ResizeObserver loop limit exceeded',
            'ResizeObserver loop completed with undelivered notifications',
            'Non-Error promise rejection captured',
            'Network request failed',
        ],
        beforeBreadcrumb(breadcrumb) {
            if (breadcrumb.data) {
                breadcrumb.data = scrub(breadcrumb.data);
                if (typeof breadcrumb.data.url === 'string') {
                    breadcrumb.data.url = scrubUrl(breadcrumb.data.url);
                }
            }
            // A DOM breadcrumb's `message` can carry visible text for some element types; the
            // element selector Sentry also records is enough to know what was interacted with.
            if (breadcrumb.category === 'ui.click' || breadcrumb.category === 'ui.input') {
                delete breadcrumb.message;
            }

            return breadcrumb;
        },
        beforeSend(event) {
            if (event.request) {
                if (event.request.url) {
                    event.request.url = scrubUrl(event.request.url);
                }
                delete event.request.cookies;
                delete event.request.query_string;
                if (event.request.headers) {
                    delete event.request.headers.Authorization;
                    delete event.request.headers.Cookie;
                }
            }
            if (event.extra) {
                event.extra = scrub(event.extra);
            }
            // Whatever set the user (syncMonitoringUser, normally) is trusted to have already used
            // only a pseudonymous id -- this just enforces that nothing else ever rides along on it.
            if (event.user) {
                event.user = event.user.id ? { id: event.user.id } : undefined;
            }

            return event;
        },
    });
}

/** Keeps Sentry's user context (a pseudonymous id only -- see the module docblock) current as Inertia navigates; a safe no-op when Sentry was never initialized. */
export function syncMonitoringUser(pseudonym) {
    Sentry.setUser(pseudonym ? { id: pseudonym } : null);
}

export { Sentry };
