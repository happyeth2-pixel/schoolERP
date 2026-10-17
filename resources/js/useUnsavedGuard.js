import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Stops typed-but-unsaved work from disappearing by accident.
 *  - closing the tab / reloading: the browser's own "Leave site?" question;
 *  - following a link inside the app (menu, back-to-list link, ...): `ask()` shows OUR dialog first;
 *    it resolves true = leave anyway, false = stay.
 * Our own saves are POST/DELETE visits and are never blocked; only navigation (GET) is.
 * (The browser's Back button inside the app is not intercepted.)
 */
export function useUnsavedGuard(dirty, ask) {
    const dirtyRef = useRef(dirty);
    dirtyRef.current = dirty;
    const askRef = useRef(ask);
    askRef.current = ask;
    const allowed = useRef(false);

    useEffect(() => {
        const beforeUnload = (event) => {
            if (dirtyRef.current && !allowed.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', beforeUnload);

        const off = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (!dirtyRef.current || allowed.current || visit.method !== 'get') return undefined;

            Promise.resolve(askRef.current()).then((leave) => {
                if (!leave) return;
                allowed.current = true;
                router.visit(visit.url.href ?? String(visit.url), { method: 'get', replace: visit.replace });
            });

            return false;
        });

        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            off();
        };
    }, []);
}
