import { motion } from 'framer-motion';

// The Dashboard home's own stat tile -- frosted glass, a gradient icon chip with a matching colour
// glow, and a staggered entrance -- used only on Dashboard.jsx (OrgDashboard/AccountantDashboard).
// GradientStatTile/Card3D are left exactly as they are everywhere else in the app (every other page
// that renders a stat tile keeps the flat, non-decorative look deliberately chosen for low-literacy
// users); this is a separate component so that choice is never disturbed.
const ACCENT = {
    blue: { ring: 'ring-sky-400/30', icon: 'from-sky-400 to-sky-600', glow: 'shadow-[0_0_28px_-8px_rgba(56,189,248,0.6)]' },
    purple: { ring: 'ring-violet-400/30', icon: 'from-violet-400 to-violet-600', glow: 'shadow-[0_0_28px_-8px_rgba(167,139,250,0.6)]' },
    green: { ring: 'ring-emerald-400/30', icon: 'from-emerald-400 to-emerald-600', glow: 'shadow-[0_0_28px_-8px_rgba(52,211,153,0.6)]' },
    amber: { ring: 'ring-amber-400/30', icon: 'from-amber-400 to-amber-600', glow: 'shadow-[0_0_28px_-8px_rgba(251,191,36,0.6)]' },
    red: { ring: 'ring-rose-400/30', icon: 'from-rose-400 to-rose-600', glow: 'shadow-[0_0_28px_-8px_rgba(251,113,133,0.6)]' },
    gold: { ring: 'ring-brand-400/30', icon: 'from-brand-400 to-brand-600', glow: 'shadow-[0_0_28px_-8px_rgba(201,162,75,0.6)]' },
};

export default function GlassStatTile({ icon: Icon, label, value, color = 'gold', index = 0 }) {
    const accent = ACCENT[color] ?? ACCENT.gold;

    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, delay: Math.min(index, 8) * 0.05, ease: 'easeOut' }}
            whileHover={{ y: -3 }}
            className={`dash-glass relative overflow-hidden rounded-2xl p-5 ring-1 transition-shadow duration-300 hover:shadow-lg ${accent.ring}`}
        >
            <div className="flex items-center gap-4">
                <div className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br text-white ${accent.icon} ${accent.glow}`}>
                    <Icon className="h-6 w-6" />
                </div>
                <div className="min-w-0">
                    <p className="text-sm font-medium text-stone-600 dark:text-stone-300">{label}</p>
                    <p className="text-2xl font-bold tracking-tight text-stone-900 dark:text-white">{value}</p>
                </div>
            </div>
        </motion.div>
    );
}
