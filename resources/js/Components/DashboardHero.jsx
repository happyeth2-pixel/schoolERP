import { usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useTranslation } from 'react-i18next';

const container = { hidden: {}, show: { transition: { staggerChildren: 0.09 } } };
const item = { hidden: { opacity: 0, y: 14 }, show: { opacity: 1, y: 0, transition: { duration: 0.5, ease: 'easeOut' } } };

/**
 * The Dashboard home's own hero banner -- a dark forest-green glass panel with drifting blurred gold/
 * emerald/sky orbs behind it, a shimmering greeting, and a row of glass "at a glance" chips for the
 * handful of numbers that matter most. Always the same dark jewel-toned treatment regardless of the
 * app's own light/dark toggle (like the public marketing site's nav), since it's a banner, not a
 * content surface -- everything below it still follows light/dark normally.
 *
 * Dashboard.jsx only; nothing here is reused by another page.
 */
export default function DashboardHero({ stats }) {
    const { auth, today } = usePage().props;
    const { t } = useTranslation();

    const weekday = today ? t(`timetable.day_${today.weekday}`) : null;

    const chips = [
        stats.total_students != null && { key: 'students', label: t('dashboard.total_students'), value: stats.total_students },
        stats.total_teachers != null && { key: 'teachers', label: t('dashboard.total_teachers'), value: stats.total_teachers },
        stats.today_attendance_rate != null && { key: 'attendance', label: t('dashboard.today_attendance'), value: `${stats.today_attendance_rate}%` },
        stats.fee_collection_rate != null && { key: 'fees', label: t('dashboard.fee_collection_rate'), value: `${stats.fee_collection_rate}%` },
    ].filter(Boolean);

    return (
        <motion.div
            variants={container}
            initial="hidden"
            animate="show"
            className="dash-hero relative overflow-hidden rounded-3xl p-6 text-white shadow-[0_24px_60px_-24px_rgba(8,40,32,0.65)] sm:p-10"
        >
            <span className="dash-orb dash-orb-1 -top-16 -left-10 h-56 w-56 bg-brand-400" aria-hidden="true" />
            <span className="dash-orb dash-orb-2 -right-16 top-6 h-64 w-64 bg-emerald-400" aria-hidden="true" />
            <span className="dash-orb dash-orb-3 bottom-[-4rem] left-1/3 h-48 w-48 bg-sky-400" aria-hidden="true" />

            <div className="relative">
                <motion.div variants={item} className="flex items-center gap-3">
                    <span className="dash-glass-dark flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full">
                        <img src="/images/brand/paradise-logo.jpg" alt="" className="h-full w-full object-cover" />
                    </span>
                    {today && (
                        <p className="text-sm font-medium text-white/70">
                            <time dateTime={today.iso}>{weekday}, {today.formatted}</time>
                            {today.academic_year && (
                                <span className="ml-2 rounded-full bg-white/10 px-2 py-0.5 text-xs font-semibold">{today.academic_year}</span>
                            )}
                        </p>
                    )}
                </motion.div>

                <motion.h1 variants={item} className="dash-shimmer mt-4 font-display text-3xl font-bold tracking-tight sm:text-4xl">
                    {t('dashboard.welcome', { name: auth.user.name })}
                </motion.h1>

                {auth.user.title && <motion.p variants={item} className="mt-1 font-medium text-brand-200/90">{auth.user.title}</motion.p>}

                <motion.p variants={item} className="mt-2 max-w-xl text-white/80">
                    {t('dashboard.hero_tagline', "Here's how School of Paradise Hills is doing today.")}
                </motion.p>

                {chips.length > 0 && (
                    <motion.div variants={item} className="mt-6 flex flex-wrap gap-3">
                        {chips.map((c) => (
                            <span key={c.key} className="dash-glass-dark flex items-center gap-2 rounded-full px-4 py-2 text-sm">
                                <span className="font-bold text-brand-200">{c.value}</span>
                                <span className="text-white/70">{c.label}</span>
                            </span>
                        ))}
                    </motion.div>
                )}
            </div>
        </motion.div>
    );
}
