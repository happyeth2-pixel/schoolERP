import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// One connection, shared by every page: AuthenticatedLayout's NotificationBell subscribes to the
// signed-in user's own private channel on it. /broadcasting/auth (which proves who's allowed to listen)
// requires a signed-in session and its own rate limit -- see bootstrap/app.php and RateLimitServiceProvider.
//
// Deliberately skipped entirely when no Reverb key was baked in at build time (VITE_REVERB_APP_KEY unset
// on whatever hosted this build) -- Pusher's own constructor throws synchronously on an empty key, and
// since this file runs before anything else, an uncaught throw here blanks the ENTIRE app, every page,
// for every visitor, not just real-time notifications. Everywhere window.Echo is used already checks it
// exists first (see AuthenticatedLayout.jsx) -- the real-time push just silently doesn't happen instead,
// same "never block on this" rule the backend's own broadcasting already follows (see RealtimeBroadcastTest).
if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
