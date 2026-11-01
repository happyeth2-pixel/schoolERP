import { Link } from '@inertiajs/react';

// A calm, warm surface instead of the old pastel indigo/sky/rose gradient and floating 3D shapes: one
// flat paper tone, a faint cross motif (restrained, geometric -- not a decoration you're meant to notice)
// and a plain bordered card. The brand mark is the only colour on the page.
export default function GuestLayout({ children }) {
    return (
        <div className="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-stone-50 px-4 py-10 dark:bg-stone-950">
            <svg className="pointer-events-none absolute inset-0 h-full w-full text-brand-900/[0.03] dark:text-brand-300/[0.04]" aria-hidden="true">
                <defs>
                    <pattern id="guest-cross" width="56" height="56" patternUnits="userSpaceOnUse">
                        <path d="M28 14v28M14 28h28" stroke="currentColor" strokeWidth="1.5" fill="none" />
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#guest-cross)" />
            </svg>

            <div className="relative flex flex-col items-center">
                <Link href="/" className="flex h-16 w-16 items-center justify-center rounded-full border-2 border-brand-400 bg-white p-1 shadow-sm">
                    <img src="/images/brand/paradise-logo.jpg" alt="School of Paradise Hills crest" className="h-full w-full rounded-full object-cover" />
                </Link>

                <div className="relative mt-8 w-full max-w-md overflow-hidden rounded-xl border border-stone-200 bg-white px-6 py-4 shadow-sm dark:border-stone-700 dark:bg-stone-900">
                    {children}
                </div>
            </div>
        </div>
    );
}
