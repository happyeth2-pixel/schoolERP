import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import i18n from './i18n';
import { initMonitoring, syncMonitoringUser, Sentry } from './monitoring';
import { initTheme } from './theme';

initMonitoring();
initTheme();

// Inertia navigations after login never re-render app.blade.php's inline script (that only
// reflects who was signed in at the last FULL page load), so the pseudonymous actor Sentry
// reports errors under is instead kept current from Inertia's own shared props on every visit.
router.on('navigate', (event) => {
    syncMonitoringUser(event.detail.page.props.monitoringUserPseudonym);
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/** Shown instead of a blank white screen if a page genuinely crashes; Sentry.ErrorBoundary reports the error and renders this. */
function CrashFallback() {
    return (
        <div className="flex min-h-screen items-center justify-center bg-stone-50 px-4 dark:bg-stone-900">
            <div className="max-w-sm text-center">
                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">Something went wrong.</p>
                <p className="mt-2 text-sm text-stone-500 dark:text-stone-400">
                    This has been reported. Reloading the page usually fixes it.
                </p>
                <button
                    type="button"
                    onClick={() => window.location.reload()}
                    className="mt-4 rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500"
                >
                    Reload
                </button>
            </div>
        </div>
    );
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const initialLocale = props.initialPage?.props?.locale;
        if (initialLocale) {
            i18n.changeLanguage(initialLocale);
        }
        syncMonitoringUser(props.initialPage?.props?.monitoringUserPseudonym);

        const root = createRoot(el);

        root.render(
            <Sentry.ErrorBoundary fallback={<CrashFallback />}>
                <App {...props} />
            </Sentry.ErrorBoundary>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});
