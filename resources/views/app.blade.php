<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts: IBM Plex Sans for Latin script (English, Afaan Oromoo), Noto Sans Ethiopic for Ge'ez
             script (Amharic, Tigrinya) -- see tailwind.config.js's `ethiopic` font family. Playfair Display
             is the editorial display serif used only by the public marketing pages (Home/About/Contact) --
             see tailwind.config.js's `display` font family. -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600,700|noto-sans-ethiopic:400,500,700|playfair-display:500,600,700,800,900&display=swap" rel="stylesheet" />

        <link rel="icon" href="/images/brand/paradise-logo.jpg" type="image/jpeg">

        <!-- Frontend error monitoring config (see MONITORING.md). Injected per-request, server-side,
             rather than baked into the built JS bundle at compile time, so the one build artifact
             this app ships is correct in every environment. dsn is null (monitoring.js then does
             nothing) on local/testing and whenever SENTRY_JS_DSN isn't set. Who is signed in is NOT
             set here -- Inertia's own SPA navigation after login never re-renders this script, so
             that comes from the "monitoringUserPseudonym" shared prop instead (kept current on
             every visit; see resources/js/monitoring.js and HandleInertiaRequests::share()). -->
        <script>
            window.__MONITORING__ = {
                dsn: @json(config('sentry.javascript_dsn')),
                environment: @json(config('sentry.environment')),
                release: @json(config('sentry.release')),
            };
        </script>

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
