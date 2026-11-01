import { SpeakerWaveIcon } from '@heroicons/react/24/solid';
import { Link, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useState } from 'react';

const NAV_LINKS = [
    { label: 'Home', href: '/' },
    { label: 'About Us', href: '/about' },
    { label: 'Academics', href: '/#academics' },
    { label: 'Admissions', href: '/#admissions' },
    { label: 'School Life', href: '/#school-life' },
    { label: 'Contact', href: '/contact' },
];

function LogoBadge({ size = 44 }) {
    return (
        <span
            className="ph-glass-light inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full shadow-[0_6px_18px_-6px_rgba(0,0,0,0.45)]"
            style={{ width: size, height: size }}
        >
            <img src="/images/brand/paradise-logo.jpg" alt="School of Paradise Hills crest" className="h-[86%] w-[86%] rounded-full object-cover" />
        </span>
    );
}

function NavAnchor({ href, label, onClick, className }) {
    const isHash = href.includes('#');

    if (!isHash) {
        return <Link href={href} onClick={onClick} className={className}>{label}</Link>;
    }

    const handle = (e) => {
        const id = href.split('#')[1];
        if (typeof window !== 'undefined' && window.location.pathname === '/') {
            e.preventDefault();
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        onClick?.();
    };

    return <a href={href} onClick={handle} className={className}>{label}</a>;
}

/** Shared chrome for the public marketing site (Home/About/Contact): a floating glass navbar that firms
    up on scroll, and a simple dark footer. Everything else (hero, sections) is per-page. */
export default function MarketingLayout({ children }) {
    const { auth, announcement } = usePage().props;
    const [scrolled, setScrolled] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 24);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <div className="ph-site min-h-screen bg-[var(--ph-ivory)] font-sans text-[var(--ph-ink)]">
            <header className="fixed inset-x-0 top-0 z-50 flex flex-col items-center">
                {announcement && (
                    <div className="flex w-full items-center justify-center gap-2 px-4 py-2 text-center text-sm font-medium text-[#2a2008]" style={{ background: 'linear-gradient(90deg, var(--ph-gold-light) 0%, var(--ph-gold) 100%)' }} data-testid="site-announcement-banner">
                        <SpeakerWaveIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span className="truncate">{announcement}</span>
                    </div>
                )}
                <div className="flex w-full justify-center px-3 pt-3 sm:px-6 sm:pt-5">
                <nav
                    className={`flex w-full max-w-6xl items-center justify-between gap-3 rounded-2xl px-4 py-2.5 transition-all duration-300 sm:px-6 ${
                        scrolled ? 'ph-glass shadow-[0_8px_30px_-12px_rgba(0,0,0,0.5)]' : 'bg-transparent'
                    }`}
                    style={scrolled ? { background: 'rgba(14,59,46,0.72)' } : undefined}
                >
                    <Link href="/" className="flex items-center gap-2.5">
                        <LogoBadge size={40} />
                        <span className="hidden font-display text-lg font-semibold tracking-tight text-white sm:inline">Paradise Hills</span>
                    </Link>

                    <div className="hidden items-center gap-1 lg:flex">
                        {NAV_LINKS.map((l) => (
                            <NavAnchor
                                key={l.label}
                                href={l.href}
                                label={l.label}
                                className="rounded-full px-3.5 py-2 text-sm font-medium text-white/85 transition hover:bg-white/10 hover:text-white"
                            />
                        ))}
                    </div>

                    <div className="hidden items-center gap-3 lg:flex">
                        <Link href={auth?.user ? route('dashboard') : route('login')} className="rounded-full px-3.5 py-2 text-sm font-medium text-white/85 transition hover:bg-white/10 hover:text-white">
                            {auth?.user ? 'Dashboard' : 'Login'}
                        </Link>
                        <Link href="/contact" className="ph-btn-gold rounded-full px-5 py-2.5 text-sm font-semibold text-[#2a2008]">
                            Contact Us
                        </Link>
                    </div>

                    <button
                        type="button"
                        onClick={() => setMenuOpen((v) => !v)}
                        aria-label="Toggle menu"
                        aria-expanded={menuOpen}
                        className="flex h-11 w-11 items-center justify-center rounded-full text-white lg:hidden"
                    >
                        <span className="relative block h-4 w-6">
                            <span className={`absolute left-0 top-0 h-0.5 w-6 bg-current transition-transform ${menuOpen ? 'translate-y-[7px] rotate-45' : ''}`} />
                            <span className={`absolute left-0 top-[7px] h-0.5 w-6 bg-current transition-opacity ${menuOpen ? 'opacity-0' : ''}`} />
                            <span className={`absolute left-0 top-[14px] h-0.5 w-6 bg-current transition-transform ${menuOpen ? '-translate-y-[7px] -rotate-45' : ''}`} />
                        </span>
                    </button>
                </nav>
                </div>
            </header>

            <AnimatePresence>
                {menuOpen && (
                    <motion.div
                        initial={{ opacity: 0, y: -12 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -12 }}
                        transition={{ duration: 0.2 }}
                        className="fixed inset-x-3 top-[72px] z-40 rounded-2xl p-3 lg:hidden"
                        style={{ background: 'rgba(14,59,46,0.96)' }}
                    >
                        <div className="flex flex-col gap-1">
                            {NAV_LINKS.map((l) => (
                                <NavAnchor
                                    key={l.label}
                                    href={l.href}
                                    label={l.label}
                                    onClick={() => setMenuOpen(false)}
                                    className="rounded-xl px-4 py-3.5 text-base font-medium text-white/90 hover:bg-white/10"
                                />
                            ))}
                            <Link
                                href={auth?.user ? route('dashboard') : route('login')}
                                onClick={() => setMenuOpen(false)}
                                className="mt-2 rounded-xl px-4 py-3.5 text-center text-base font-medium text-white/90 hover:bg-white/10"
                            >
                                {auth?.user ? 'Dashboard' : 'Login'}
                            </Link>
                            <Link
                                href="/contact"
                                onClick={() => setMenuOpen(false)}
                                className="ph-btn-gold rounded-xl px-4 py-3.5 text-center text-base font-semibold text-[#2a2008]"
                            >
                                Contact Us
                            </Link>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <main>{children}</main>

            <footer className="relative overflow-hidden px-4 py-16" style={{ background: 'linear-gradient(180deg, var(--ph-forest) 0%, var(--ph-forest-dark) 100%)' }}>
                <div className="mx-auto grid max-w-6xl gap-10 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <div className="flex items-center gap-2.5">
                            <LogoBadge size={40} />
                            <span className="font-display text-lg font-semibold text-white">Paradise Hills</span>
                        </div>
                        <p className="mt-4 max-w-xs text-sm leading-relaxed text-white/60">
                            A school in Ashewa Meda, Burayu, built on honesty, hard work, and a love of learning.
                        </p>
                    </div>

                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wider text-[var(--ph-gold-light)]">Explore</p>
                        <ul className="mt-4 space-y-2.5 text-sm text-white/70">
                            <li><Link href="/" className="hover:text-white">Home</Link></li>
                            <li><Link href="/about" className="hover:text-white">About Us</Link></li>
                            <li><Link href="/contact" className="hover:text-white">Contact</Link></li>
                            <li><Link href={route('login')} className="hover:text-white">Portal Login</Link></li>
                        </ul>
                    </div>

                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wider text-[var(--ph-gold-light)]">Visit</p>
                        <ul className="mt-4 space-y-2.5 text-sm text-white/70">
                            <li>Ashewa Meda, Burayu Town</li>
                            <li>Oromia Special Zone, near Addis Ababa</li>
                            <li>Established 2008 E.C.</li>
                        </ul>
                    </div>

                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wider text-[var(--ph-gold-light)]">Reach Us</p>
                        <ul className="mt-4 space-y-2.5 text-sm text-white/70">
                            <li><a href="tel:+251934221240" className="hover:text-white">+251 93 422 1240</a></li>
                        </ul>
                    </div>
                </div>

                <div className="mx-auto mt-12 max-w-6xl border-t border-white/10 pt-6 text-xs text-white/40">
                    © {new Date().getFullYear()} School of Paradise Hills. All rights reserved.
                </div>
            </footer>
        </div>
    );
}
