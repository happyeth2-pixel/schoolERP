import MarketingLayout from '@/Layouts/MarketingLayout';
import { CheckCircleIcon, ClockIcon, MapPinIcon, PhoneIcon } from '@heroicons/react/24/outline';
import { Head, useForm, usePage } from '@inertiajs/react';
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

function Field({ label, error, children }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-[var(--ph-ink)]/80">{label}</span>
            <div className="mt-1.5">{children}</div>
            {error && <span className="mt-1 block text-sm text-rose-600">{error}</span>}
        </label>
    );
}

const inputClass = 'w-full rounded-xl border-2 border-[var(--ph-beige)] bg-white px-4 py-3 text-base text-[var(--ph-ink)] outline-none transition focus:border-[var(--ph-gold)] focus:ring-4 focus:ring-[var(--ph-gold)]/15';

export default function Contact({ content }) {
    const { flash } = usePage().props;
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', phone: '', email: '', message: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('contact.submit'), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <MarketingLayout>
            <Head title="Contact Us" />

            <section className="relative overflow-hidden px-4 pb-16 pt-32 sm:px-6 sm:pt-40 lg:px-8" style={{ background: 'radial-gradient(120% 90% at 15% 0%, #123f31 0%, var(--ph-forest) 45%, var(--ph-navy) 100%)' }}>
                <div className="pointer-events-none absolute -right-10 top-0 h-80 w-80 rounded-full bg-[var(--ph-gold)]/10 blur-3xl" />
                <div className="relative mx-auto max-w-3xl text-center">
                    <FadeUp>
                        <span className="ph-glass inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-medium tracking-wide text-white/85">Get in Touch</span>
                        <h1 className="mt-6 font-display text-4xl font-semibold leading-tight text-white sm:text-5xl">{content.hero_title}</h1>
                        <p className="mx-auto mt-6 max-w-xl whitespace-pre-line text-lg leading-relaxed text-white/70">
                            {content.hero_subtitle}
                        </p>
                    </FadeUp>
                </div>
            </section>

            <section className="px-4 py-20 sm:px-6 lg:px-8">
                <div className="mx-auto grid max-w-6xl gap-10 lg:grid-cols-[0.85fr,1.15fr]">
                    <FadeUp className="space-y-5">
                        <div className="ph-cut-corner rounded-2xl p-6" style={{ background: 'linear-gradient(160deg, #ffffff 0%, var(--ph-beige) 100%)' }}>
                            <span className="ph-neu flex h-12 w-12 items-center justify-center rounded-xl">
                                <PhoneIcon className="h-6 w-6 text-[var(--ph-forest)]" />
                            </span>
                            <h3 className="mt-4 font-display text-lg font-semibold text-[var(--ph-ink)]">Call Us</h3>
                            <a href="tel:+251934221240" className="mt-1 block text-base font-medium text-[var(--ph-forest)]">+251 93 422 1240</a>
                        </div>

                        <div className="ph-cut-corner rounded-2xl p-6" style={{ background: 'linear-gradient(160deg, #ffffff 0%, var(--ph-beige) 100%)' }}>
                            <span className="ph-neu flex h-12 w-12 items-center justify-center rounded-xl">
                                <MapPinIcon className="h-6 w-6 text-[var(--ph-forest)]" />
                            </span>
                            <h3 className="mt-4 font-display text-lg font-semibold text-[var(--ph-ink)]">Visit Us</h3>
                            <p className="mt-1 whitespace-pre-line text-base text-[var(--ph-ink)]/70">{content.visit_description}</p>
                        </div>

                        <div className="ph-cut-corner rounded-2xl p-6" style={{ background: 'linear-gradient(160deg, #ffffff 0%, var(--ph-beige) 100%)' }}>
                            <span className="ph-neu flex h-12 w-12 items-center justify-center rounded-xl">
                                <ClockIcon className="h-6 w-6 text-[var(--ph-forest)]" />
                            </span>
                            <h3 className="mt-4 font-display text-lg font-semibold text-[var(--ph-ink)]">Office Hours</h3>
                            <p className="mt-1 whitespace-pre-line text-base text-[var(--ph-ink)]/70">{content.office_hours}</p>
                        </div>

                        <div className="overflow-hidden rounded-2xl border-2 border-[var(--ph-beige)]">
                            <iframe
                                title="Approximate area &mdash; Burayu, Oromia"
                                src="https://www.openstreetmap.org/export/embed.html?bbox=38.63%2C9.00%2C38.72%2C9.06&layer=mapnik"
                                className="h-56 w-full grayscale-[15%]"
                                loading="lazy"
                            />
                            <p className="bg-white px-4 py-2 text-xs text-[var(--ph-ink)]/50">Approximate area &mdash; Burayu Town, Oromia</p>
                        </div>
                    </FadeUp>

                    <FadeUp delay={0.08} className="ph-glass-light rounded-3xl p-7 sm:p-10" style={{ background: 'rgba(255,255,255,0.9)', border: '1px solid var(--ph-beige)' }}>
                        {flash?.status === 'contact-sent' ? (
                            <div className="flex flex-col items-center gap-3 py-10 text-center">
                                <CheckCircleIcon className="h-14 w-14 text-[var(--ph-forest)]" />
                                <h3 className="font-display text-2xl font-semibold text-[var(--ph-ink)]">Message sent</h3>
                                <p className="max-w-sm text-base text-[var(--ph-ink)]/70">
                                    Thank you for reaching out &mdash; the school office will get back to you soon.
                                </p>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="space-y-5" data-testid="contact-form">
                                <h3 className="font-display text-2xl font-semibold text-[var(--ph-ink)]">Send a message</h3>

                                <Field label="Your name" error={errors.name}>
                                    <input type="text" className={inputClass} value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={120} required />
                                </Field>

                                <Field label="Phone number" error={errors.phone}>
                                    <input type="tel" inputMode="tel" className={inputClass} value={data.phone} onChange={(e) => setData('phone', e.target.value)} maxLength={30} required />
                                </Field>

                                <Field label="Email (optional)" error={errors.email}>
                                    <input type="email" className={inputClass} value={data.email} onChange={(e) => setData('email', e.target.value)} maxLength={150} />
                                </Field>

                                <Field label="Message" error={errors.message}>
                                    <textarea className={inputClass} rows={5} maxLength={2000} value={data.message} onChange={(e) => setData('message', e.target.value)} required />
                                </Field>

                                <button type="submit" disabled={processing} className="ph-btn-gold w-full rounded-full px-7 py-3.5 text-base font-semibold text-[#2a2008] disabled:opacity-60">
                                    {processing ? 'Sending…' : 'Send Message'}
                                </button>
                            </form>
                        )}
                    </FadeUp>
                </div>
            </section>
        </MarketingLayout>
    );
}
