import MarketingLayout from '@/Layouts/MarketingLayout';
import {
    AcademicCapIcon,
    BookOpenIcon,
    ChatBubbleLeftRightIcon,
    HeartIcon,
    MapPinIcon,
    PhoneIcon,
    ShieldCheckIcon,
    SparklesIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline';
import { Head, Link } from '@inertiajs/react';
import { motion, useMotionValue, useSpring, useTransform } from 'framer-motion';
import { useRef } from 'react';

/** Mouse-responsive 3D tilt + parallax depth for the hero medallion, built from plain CSS transforms
    (no WebGL) -- deliberate: genuinely interactive depth without the bundle weight/mobile-GPU risk of
    Three.js, which matters more here than the effect itself (see hero hand-off notes). Disabled under
    prefers-reduced-motion by the spring's own stiffness floor plus the global CSS media guard. */
function useTilt(strength = 16) {
    const x = useMotionValue(0);
    const y = useMotionValue(0);
    const sx = useSpring(x, { stiffness: 120, damping: 14 });
    const sy = useSpring(y, { stiffness: 120, damping: 14 });
    const rotateX = useTransform(sy, [-0.5, 0.5], [strength, -strength]);
    const rotateY = useTransform(sx, [-0.5, 0.5], [-strength, strength]);

    const onMouseMove = (e) => {
        const rect = e.currentTarget.getBoundingClientRect();
        x.set((e.clientX - rect.left) / rect.width - 0.5);
        y.set((e.clientY - rect.top) / rect.height - 0.5);
    };
    const onMouseLeave = () => { x.set(0); y.set(0); };

    return { rotateX, rotateY, sx, sy, onMouseMove, onMouseLeave };
}

function Hero({ content }) {
    const tilt = useTilt();
    const panelX = useTransform(tilt.sx, [-0.5, 0.5], [-14, 14]);
    const panelY = useTransform(tilt.sy, [-0.5, 0.5], [-10, 10]);

    return (
        <section className="relative overflow-hidden pb-28 pt-32 sm:pt-40" style={{ background: 'radial-gradient(120% 90% at 15% 0%, #123f31 0%, var(--ph-forest) 45%, var(--ph-navy) 100%)' }}>
            <div className="pointer-events-none absolute inset-0 opacity-[0.07]" style={{ backgroundImage: 'linear-gradient(rgba(255,255,255,.6) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.6) 1px, transparent 1px)', backgroundSize: '56px 56px' }} />
            <div className="pointer-events-none absolute -left-24 top-10 h-72 w-72 rounded-full bg-[var(--ph-gold)]/10 blur-3xl" />
            <div className="pointer-events-none absolute -right-10 bottom-0 h-96 w-96 rounded-full bg-emerald-400/10 blur-3xl" />

            <div className="relative mx-auto grid max-w-6xl items-center gap-16 px-4 sm:px-6 lg:grid-cols-[1.05fr,0.95fr] lg:px-8">
                <motion.div initial={{ opacity: 0, y: 24 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.7, ease: 'easeOut' }}>
                    <span className="ph-glass inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-medium tracking-wide text-white/85">
                        <SparklesIcon className="h-3.5 w-3.5 text-[var(--ph-gold-light)]" />
                        {content.hero_eyebrow}
                    </span>

                    <h1 className="mt-6 font-display text-4xl font-semibold leading-[1.08] tracking-tight text-white sm:text-5xl lg:text-6xl">
                        {content.hero_title}
                    </h1>

                    <p className="mt-6 max-w-xl whitespace-pre-line text-lg leading-relaxed text-white/70">
                        {content.hero_subtitle}
                    </p>

                    <div className="mt-10 flex flex-wrap items-center gap-4">
                        <Link href="/about" className="ph-btn-gold rounded-full px-7 py-3.5 text-base font-semibold text-[#2a2008]">
                            {content.cta_primary_label}
                        </Link>
                        <a href="#admissions" className="ph-btn-ghost rounded-full px-7 py-3.5 text-base font-semibold text-white">
                            {content.cta_secondary_label}
                        </a>
                    </div>
                </motion.div>

                <motion.div
                    initial={{ opacity: 0, scale: 0.92 }}
                    animate={{ opacity: 1, scale: 1 }}
                    transition={{ duration: 0.8, ease: 'easeOut', delay: 0.15 }}
                    className="relative mx-auto h-[380px] w-full max-w-md [perspective:1200px] sm:h-[440px]"
                    onMouseMove={tilt.onMouseMove}
                    onMouseLeave={tilt.onMouseLeave}
                >
                    <motion.div style={{ rotateX: tilt.rotateX, rotateY: tilt.rotateY, transformStyle: 'preserve-3d' }} className="relative h-full w-full">
                        <div className="absolute left-1/2 top-1/2 h-64 w-64 -translate-x-1/2 -translate-y-1/2 rounded-full" style={{ background: 'radial-gradient(circle, rgba(201,162,75,0.35) 0%, transparent 70%)', transform: 'translateZ(-40px)' }} />

                        <div
                            className="ph-glass ph-floating absolute left-1/2 top-1/2 flex h-60 w-60 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full p-3 shadow-[0_30px_60px_-20px_rgba(0,0,0,0.6)] sm:h-72 sm:w-72"
                            style={{ transform: 'translateZ(60px)' }}
                        >
                            <div className="flex h-full w-full items-center justify-center rounded-full border border-[var(--ph-gold)]/40 bg-white/95">
                                <img src="/images/brand/paradise-logo.jpg" alt="School of Paradise Hills crest" className="h-[82%] w-[82%] rounded-full object-cover" />
                            </div>
                        </div>

                        <motion.div
                            style={{ x: panelX, y: panelY, transform: 'translateZ(100px)' }}
                            className="ph-glass ph-floating-delay absolute -left-2 top-4 w-48 rounded-2xl p-4 shadow-[0_20px_40px_-15px_rgba(0,0,0,0.5)] sm:-left-8"
                        >
                            <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-[var(--ph-gold-light)]">
                                <ShieldCheckIcon className="h-4 w-4" /> Our Values
                            </p>
                            <p className="mt-1.5 text-sm font-medium text-white">Be Honest &middot; Work Hard &middot; Learn</p>
                        </motion.div>

                        <motion.div
                            style={{ x: panelX, y: panelY, transform: 'translateZ(80px)' }}
                            className="ph-glass ph-floating-delay-2 absolute -right-2 bottom-6 w-52 rounded-2xl p-4 shadow-[0_20px_40px_-15px_rgba(0,0,0,0.5)] sm:-right-10"
                        >
                            <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-[var(--ph-gold-light)]">
                                <MapPinIcon className="h-4 w-4" /> Campus
                            </p>
                            <p className="mt-1.5 text-sm font-medium text-white">Spacious grounds, built for kids</p>
                        </motion.div>
                    </motion.div>
                </motion.div>
            </div>
        </section>
    );
}

function FadeUp({ children, className, delay = 0, ...rest }) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 28 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, margin: '-80px' }}
            transition={{ duration: 0.6, ease: 'easeOut', delay }}
            className={className}
            {...rest}
        >
            {children}
        </motion.div>
    );
}

function StatChip({ icon: Icon, label, value }) {
    return (
        <div className="ph-neu flex items-center gap-3 rounded-2xl px-5 py-4">
            <span className="ph-neu-inset flex h-11 w-11 shrink-0 items-center justify-center rounded-full">
                <Icon className="h-5 w-5 text-[var(--ph-forest)]" />
            </span>
            <div>
                <p className="font-display text-xl font-semibold text-[var(--ph-ink)]">{value}</p>
                <p className="text-xs text-[var(--ph-ink)]/60">{label}</p>
            </div>
        </div>
    );
}

function AboutPreview({ content }) {
    return (
        <section className="relative px-4 py-24 sm:px-6 lg:px-8">
            <div className="mx-auto grid max-w-6xl items-center gap-14 lg:grid-cols-2">
                <FadeUp>
                    <p className="text-xs font-semibold uppercase tracking-wider text-[var(--ph-forest)]">About Us</p>
                    <h2 className="mt-3 font-display text-3xl font-semibold text-[var(--ph-ink)] sm:text-4xl">
                        {content.about_title}
                    </h2>
                    <p className="mt-5 whitespace-pre-line text-base leading-relaxed text-[var(--ph-ink)]/70">
                        {content.about_body}
                    </p>
                    <Link href="/about" className="mt-6 inline-flex items-center gap-2 text-base font-semibold text-[var(--ph-forest)] hover:gap-3">
                        Read our full story <span aria-hidden="true">&rarr;</span>
                    </Link>
                </FadeUp>

                <FadeUp className="grid grid-cols-2 gap-4">
                    <StatChip icon={AcademicCapIcon} value="2008 E.C." label="Founded" />
                    <StatChip icon={MapPinIcon} value="Burayu" label="Oromia Special Zone" />
                    <StatChip icon={HeartIcon} value="Honesty" label="Core value" />
                    <StatChip icon={SparklesIcon} value="Hard Work" label="Core value" />
                </FadeUp>
            </div>
        </section>
    );
}

function PillarCard({ icon: Icon, title, children, id }) {
    return (
        <FadeUp className="ph-cut-corner relative overflow-hidden rounded-2xl p-7" style={{ background: 'linear-gradient(160deg, #ffffff 0%, var(--ph-beige) 100%)' }}>
            <span id={id} className="absolute -top-28" aria-hidden="true" />
            <span className="ph-neu flex h-12 w-12 items-center justify-center rounded-xl">
                <Icon className="h-6 w-6 text-[var(--ph-forest)]" />
            </span>
            <h3 className="mt-5 font-display text-xl font-semibold text-[var(--ph-ink)]">{title}</h3>
            <p className="mt-2.5 text-sm leading-relaxed text-[var(--ph-ink)]/70">{children}</p>
        </FadeUp>
    );
}

function Pillars({ content }) {
    return (
        <section className="relative px-4 py-24 sm:px-6 lg:px-8" style={{ background: 'var(--ph-beige)' }}>
            <div className="mx-auto max-w-6xl">
                <FadeUp className="max-w-2xl">
                    <p className="text-xs font-semibold uppercase tracking-wider text-[var(--ph-forest)]">What We Offer</p>
                    <h2 className="mt-3 font-display text-3xl font-semibold text-[var(--ph-ink)] sm:text-4xl">{content.pillars_title}</h2>
                </FadeUp>

                <div className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <PillarCard id="academics" icon={BookOpenIcon} title="Academics">
                        {content.academics_body}
                    </PillarCard>
                    <PillarCard id="admissions" icon={UserGroupIcon} title="Admissions">
                        {content.admissions_body}
                    </PillarCard>
                    <PillarCard id="school-life" icon={HeartIcon} title="School Life">
                        {content.school_life_body}
                    </PillarCard>
                </div>
            </div>
        </section>
    );
}

function Gallery({ images }) {
    if (!images?.length) return null;

    return (
        <section className="px-4 py-24 sm:px-6 lg:px-8">
            <div className="mx-auto max-w-6xl">
                <FadeUp className="max-w-xl">
                    <p className="text-xs font-semibold uppercase tracking-wider text-[var(--ph-forest)]">A Look Around</p>
                    <h2 className="mt-3 font-display text-3xl font-semibold text-[var(--ph-ink)] sm:text-4xl">Campus gallery</h2>
                </FadeUp>

                <div className="mt-10 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {images.map((img, i) => (
                        <FadeUp key={img.id} delay={(i % 4) * 0.06} className="group relative aspect-square overflow-hidden rounded-2xl">
                            <img src={img.url} alt={img.caption || 'School of Paradise Hills campus photo'} className="h-full w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy" />
                            {img.caption && (
                                <span className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-3 py-2 text-xs font-medium text-white opacity-0 transition group-hover:opacity-100">
                                    {img.caption}
                                </span>
                            )}
                        </FadeUp>
                    ))}
                </div>
            </div>
        </section>
    );
}

function ContactCta({ content }) {
    return (
        <section className="relative overflow-hidden px-4 py-24 sm:px-6 lg:px-8" style={{ background: 'linear-gradient(135deg, var(--ph-forest) 0%, var(--ph-navy) 100%)' }}>
            <div className="mx-auto flex max-w-5xl flex-col items-center gap-8 rounded-3xl p-10 text-center sm:p-14">
                <ChatBubbleLeftRightIcon className="h-10 w-10 text-[var(--ph-gold-light)]" />
                <h2 className="font-display text-3xl font-semibold text-white sm:text-4xl">{content.contact_cta_title}</h2>
                <p className="max-w-xl whitespace-pre-line text-base text-white/70">
                    {content.contact_cta_subtitle}
                </p>
                <div className="flex flex-wrap items-center justify-center gap-4">
                    <Link href="/contact" className="ph-btn-gold rounded-full px-7 py-3.5 text-base font-semibold text-[#2a2008]">
                        Contact Us
                    </Link>
                    <a href="tel:+251934221240" className="ph-btn-ghost flex items-center gap-2 rounded-full px-7 py-3.5 text-base font-semibold text-white">
                        <PhoneIcon className="h-5 w-5" /> +251 93 422 1240
                    </a>
                </div>
            </div>
        </section>
    );
}

export default function Home({ gallery, content }) {
    return (
        <MarketingLayout>
            <Head title="Paradise Hills School" />
            <Hero content={content} />
            <AboutPreview content={content} />
            <Pillars content={content} />
            <Gallery images={gallery} />
            <ContactCta content={content} />
        </MarketingLayout>
    );
}
