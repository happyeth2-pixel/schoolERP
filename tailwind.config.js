import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // 'class' instead of the default 'media' -- lets users manually
    // toggle dark mode instead of only following the OS setting.
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // .js too: shared class strings live in plain .js files (Components/ui/tokens.js). Tailwind only
        // generates classes it can see, so anything not matched here silently has no CSS.
        './resources/js/**/*.{js,jsx}',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Latin script (English, Afaan Oromoo): a professional humanist sans, not the Laravel-starter
                // default. Ethiopic script (Amharic, Tigrinya) needs its own typeface -- Latin fonts have no
                // Ge'ez glyphs at all, so without this the browser silently substitutes whatever Ethiopic font
                // happens to be installed, at a mismatched weight/size to the rest of the UI. Applied via the
                // `font-ethiopic` class the two root layouts set reactively from the live i18next language
                // (see AuthenticatedLayout.jsx / GuestLayout.jsx) -- not the `<html lang>` attribute, which
                // Inertia's SPA navigation does not keep in sync with an in-page language switch.
                sans: ['"IBM Plex Sans"', ...defaultTheme.fontFamily.sans],
                ethiopic: ['"Noto Sans Ethiopic"', '"IBM Plex Sans"', ...defaultTheme.fontFamily.sans],
                // Editorial display serif -- the public marketing pages (Home/About/Contact) only, for
                // headlines in the "premium school website" visual identity. Nothing in the ERP itself uses it.
                display: ['"Playfair Display"', 'serif'],
            },
            colors: {
                // The brand/action colour -- the School of Paradise Hills' own muted gold, replacing the
                // former clay terracotta so the whole app (not just the public marketing site) shares one
                // identity. Deliberately NOT forest green: the app's "success"/"Paid"/"Present" badges are
                // `emerald` (see ui/tokens.js TONE), and a green primary button sitting next to a green
                // success badge would blur exactly the distinction that scheme depends on. Gold carries no
                // such collision. 600/700/800 stay >=4.5:1 against white text (gold cannot go as dark as the
                // old terracotta without reading as brown/khaki instead of gold -- 700/800/900 comfortably
                // clear 7:1+, same bar the old terracotta met).
                brand: {
                    50: '#FBF6EA', 100: '#F6EAD2', 200: '#EBD3A0', 300: '#D9B966', 400: '#C9A24B',
                    500: '#A5801F', 600: '#7D621D', 700: '#705420', 800: '#523D19', 900: '#3A2B13',
                },
                // Unchanged alias, same values as `brand` 100-600 -- kept so any `gold-*` class already
                // written (e.g. the login page's password-reminder banner) still matches the brand gold.
                gold: {
                    100: '#F6EAD2', 300: '#D9B966', 400: '#C9A24B', 500: '#A5801F', 600: '#7D621D',
                },
                // The School of Paradise Hills' deep forest green -- the dark structural accent (the
                // sidebar rail, matching the public site's dark nav/footer) alongside the gold brand colour.
                // Never used for status/semantic meaning, only chrome, so it can't collide with `emerald`.
                forest: {
                    50: '#EAF2EE', 100: '#CFE3DA', 300: '#6FA88F', 500: '#1F5C47',
                    700: '#123F31', 800: '#0E3B2E', 900: '#082820', 950: '#051C16',
                },
            },
        },
    },

    plugins: [forms],
};
