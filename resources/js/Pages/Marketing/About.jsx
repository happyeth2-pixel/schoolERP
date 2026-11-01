import MarketingLayout from '@/Layouts/MarketingLayout';
import { BookOpenIcon, HandRaisedIcon, MapPinIcon, SparklesIcon } from '@heroicons/react/24/outline';
import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';

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

const VALUE_ICONS = [HandRaisedIcon, SparklesIcon, BookOpenIcon];

export default function About({ content }) {
    const values = [1, 2, 3].map((n) => ({
        icon: VALUE_ICONS[n - 1],
        title: content[`value_${n}_title`],
        body: content[`value_${n}_body`],
    }));

    return (
        <MarketingLayout>
            <Head title="About Us" />

            <section className="relative overflow-hidden px-4 pb-20 pt-32 sm:px-6 sm:pt-40 lg:px-8" style={{ background: 'radial-gradient(120% 90% at 85% 0%, #123f31 0%, var(--ph-forest) 45%, var(--ph-navy) 100%)' }}>
                <div className="pointer-events-none absolute -left-10 top-0 h-80 w-80 rounded-full bg-[var(--ph-gold)]/10 blur-3xl" />
                <div className="relative mx-auto max-w-4xl text-center">
                    <FadeUp>
                        <span className="ph-glass inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-medium tracking-wide text-white/85">
                            About School of Paradise Hills
                        </span>
                        <h1 className="mt-6 font-display text-4xl font-semibold leading-tight text-white sm:text-5xl">
                            {content.hero_title}
                        </h1>
                        <p className="mx-auto mt-6 max-w-2xl whitespace-pre-line text-lg leading-relaxed text-white/70">
                            {content.hero_subtitle}
                        </p>
                    </FadeUp>
                </div>
            </section>

            <section className="px-4 py-24 sm:px-6 lg:px-8">
                <div className="mx-auto grid max-w-6xl items-start gap-14 lg:grid-cols-[1fr,0.8fr]">
                    <FadeUp className="space-y-6 whitespace-pre-line text-base leading-relaxed text-[var(--ph-ink)]/75">
                        <p>{content.story_paragraph_1}</p>
                        <p>{content.story_paragraph_2}</p>
                        <p>{content.story_paragraph_3}</p>
                    </FadeUp>

                    <FadeUp delay={0.1} className="ph-glass rounded-3xl p-2" style={{ background: 'linear-gradient(160deg, var(--ph-forest) 0%, var(--ph-navy) 100%)' }}>
                        <div className="rounded-[20px] p-8">
                            <div className="mx-auto flex h-32 w-32 items-center justify-center rounded-full border border-[var(--ph-gold)]/40 bg-white/95 p-2">
                                <img src="/images/brand/paradise-logo.jpg" alt="School of Paradise Hills crest" className="h-full w-full rounded-full object-cover" />
                            </div>
                            <dl className="mt-8 space-y-5">
                                <div className="flex items-start gap-3">
                                    <MapPinIcon className="mt-0.5 h-5 w-5 shrink-0 text-[var(--ph-gold-light)]" />
                                    <div>
                                        <dt className="text-xs font-semibold uppercase tracking-wide text-white/50">Location</dt>
                                        <dd className="text-sm text-white/85">Ashewa Meda, Burayu Town, Oromia Special Zone</dd>
                                    </div>
                                </div>
                                <div className="flex items-start gap-3">
                                    <SparklesIcon className="mt-0.5 h-5 w-5 shrink-0 text-[var(--ph-gold-light)]" />
                                    <div>
                                        <dt className="text-xs font-semibold uppercase tracking-wide text-white/50">Established</dt>
                                        <dd className="text-sm text-white/85">2008 E.C.</dd>
                                    </div>
                                </div>
                            </dl>
                        </div>
                    </FadeUp>
                </div>
            </section>

            <section className="px-4 py-24 sm:px-6 lg:px-8" style={{ background: 'var(--ph-beige)' }}>
                <div className="mx-auto max-w-6xl">
                    <FadeUp className="max-w-xl">
                        <p className="text-xs font-semibold uppercase tracking-wider text-[var(--ph-forest)]">What We Stand For</p>
                        <h2 className="mt-3 font-display text-3xl font-semibold text-[var(--ph-ink)] sm:text-4xl">Our values</h2>
                    </FadeUp>

                    <div className="mt-10 grid gap-6 sm:grid-cols-3">
                        {values.map((v, i) => (
                            <FadeUp key={v.title} delay={i * 0.08} className="ph-cut-corner rounded-2xl bg-white p-7 shadow-sm">
                                <span className="ph-neu flex h-12 w-12 items-center justify-center rounded-xl">
                                    <v.icon className="h-6 w-6 text-[var(--ph-forest)]" />
                                </span>
                                <h3 className="mt-5 font-display text-xl font-semibold text-[var(--ph-ink)]">{v.title}</h3>
                                <p className="mt-2.5 whitespace-pre-line text-sm leading-relaxed text-[var(--ph-ink)]/70">{v.body}</p>
                            </FadeUp>
                        ))}
                    </div>
                </div>
            </section>

            <section className="px-4 py-20 text-center sm:px-6 lg:px-8">
                <FadeUp className="mx-auto max-w-2xl">
                    <h2 className="font-display text-3xl font-semibold text-[var(--ph-ink)] sm:text-4xl">{content.cta_title}</h2>
                    <p className="mt-4 whitespace-pre-line text-base text-[var(--ph-ink)]/70">{content.cta_subtitle}</p>
                    <Link href="/contact" className="ph-btn-gold mt-8 inline-block rounded-full px-7 py-3.5 text-base font-semibold text-[#2a2008]">
                        Get in Touch
                    </Link>
                </FadeUp>
            </section>
        </MarketingLayout>
    );
}
